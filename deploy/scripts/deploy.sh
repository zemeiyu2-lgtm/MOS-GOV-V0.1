#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 可重复发布（固定 commit + 固定上游源码归档）
# 用法: mosgov-deploy.sh --release <40-hex-commit> [--dry-run] [--no-http-health]
#
# 发布单元 = ChurchCRM 7.8.0 锁定源码归档 + 指定 commit 的 MOS-GOV 插件。
# 两个来源都必须在 deploy/upstream-lock.json 中固定；分支 HEAD / latest /
# 自动拉取上游一律拒绝。数据库迁移不在这里执行，由 provision-tenant.sh 负责。
#
# 闸门顺序：锁定校验 → 源码组装 → 全量 PHP 语法 → 配置体检 → Apache 配置 →
#          symlink 原子切换 → 健康检查（失败自动回滚到上一个发布）。
# =============================================================================
set -euo pipefail
export MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"; fi

need_root
for c in git tar jq sha256sum apache2ctl; do need_cmd "$c"; done
PHP_BIN="${MOSGOV_PHP_BIN:-php8.4}"
command -v "$PHP_BIN" >/dev/null 2>&1 || PHP_BIN=php
need_cmd "$PHP_BIN"

release=""
dry=0
no_http=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --release)        release="${2:-}"; shift 2 ;;
        --dry-run)        dry=1; shift ;;
        --no-http-health) no_http=1; shift ;;
        *) die "未知参数：$1" ;;
    esac
done
is_pinned_commit "$release" || die "必须传入 40 位十六进制 commit（禁止分支名 / HEAD / latest）"

# ---- 模板 / 锁定清单位置 -----------------------------------------------------
tpl_dir="$MOSGOV_ETC/deploy"
[[ -f "$tpl_dir/upstream-lock.json" ]] || tpl_dir="$MOSGOV_SELF_DIR/.."
lock="$tpl_dir/upstream-lock.json"
[[ -f "$lock" ]] || die "缺少发布锁定清单：$lock"

crm_archive="$(jq -r '.churchcrm.archive_path' "$lock")"
crm_sha="$(jq -r '.churchcrm.sha256' "$lock")"
crm_ver="$(jq -r '.churchcrm.version' "$lock")"
plugin_ver="$(jq -r '.plugin.version' "$lock")"
repo_url="$(jq -r '.plugin.repository' "$lock")"

[[ "$crm_sha" =~ ^[0-9a-f]{64}$ ]] \
    || die "upstream-lock.json 的 churchcrm.sha256 仍是占位值。必须由运维上传经核验的 ChurchCRM $crm_ver 源码归档并填写真实 sha256 —— 这是正式上线阻断项，禁止用推测值填充。"
[[ -f "$crm_archive" ]] || die "ChurchCRM 源码归档不存在：$crm_archive"
[[ "$(sha256_of "$crm_archive")" == "$crm_sha" ]] \
    || die "ChurchCRM 源码归档 sha256 与锁定值不符，拒绝发布"
log "ChurchCRM $crm_ver 归档校验通过 sha256=${crm_sha:0:16}…"

# ---- 上游补丁绑定校验（dry-run 也会执行）------------------------------------
# 补丁直接改写上游源码。缺文件 / sha256 漂移 / 未填真实值，一律阻断发布，
# 而不是留给运维"记得手工打补丁"。实际应用在组装阶段执行一次。
patch_count="$(jq -r '(.patches // []) | length' "$lock")"
if [[ "$patch_count" -gt 0 ]]; then
    while IFS=$'\t' read -r pid ppath psha; do
        [[ -n "$ppath" && "$ppath" != "null" ]] || die "补丁 $pid 缺少 patch_path"
        [[ "$psha" =~ ^[0-9a-f]{64}$ ]] \
            || die "补丁 $pid 的 patch_sha256 未填写真实值（发布阻断项，禁止推测值）"
        pfile="$tpl_dir/../$ppath"
        [[ -f "$pfile" ]] || die "补丁文件不存在：$ppath（补丁 $pid）"
        [[ "$(sha256_of "$pfile")" == "$psha" ]] \
            || die "补丁 $pid 文件 sha256 与锁定值不符，拒绝发布"
        log "补丁 $pid 绑定校验通过 sha256=${psha:0:16}…"
    done < <(jq -r '(.patches // [])[] | [.id, .patch_path, .patch_sha256] | @tsv' "$lock")
fi

# ---- git 固定镜像 ------------------------------------------------------------
mirror="/var/www/mos-gov/mirror/MOS-GOV-V0.1.git"
if [[ ! -d "$mirror" ]]; then
    install -d -m 0755 "$(dirname "$mirror")"
    git clone --quiet --mirror "$repo_url" "$mirror"
else
    git -C "$mirror" fetch --quiet --all --tags
fi
git -C "$mirror" cat-file -e "${release}^{commit}" 2>/dev/null \
    || die "commit $release 在 $repo_url 中不存在"
log "插件 commit 校验通过 ${release:0:8}（仓库 $(git -C "$mirror" rev-parse --short "$release")）"

if [[ "$dry" -eq 1 ]]; then
    log "DRY-RUN：锁定校验、源码归档、commit 均已通过；未创建发布目录、未切换 symlink。"
    log "DRY-RUN：正式执行将组装 $MOSGOV_RELEASE_ROOT/$release 并运行全量 php -l。"
    exit 0
fi

# ---- 组装发布目录 ------------------------------------------------------------
install -d -m 0755 "$MOSGOV_RELEASE_ROOT"
target="$MOSGOV_RELEASE_ROOT/$release"
if [[ -d "$target" && -f "$target/REVISION" ]]; then
    log "复用已存在的发布目录 $target（幂等重发）"
else
    [[ -e "$target" ]] && die "发布目录已存在但不完整，拒绝覆盖：$target"
    install -d -m 0755 "$target"
    # ChurchCRM 归档约定：单一顶层目录，内部即 Web 根内容。
    tar -xzf "$crm_archive" --strip-components=1 -C "$target"
    [[ -d "$target/Include" ]] || die "源码归档结构不符：缺少 Include/ 目录"

    # 应用上游安全补丁（绑定已在前面校验）。失败即视为发布失败，
    # 绝不允许"带着未修补的上游源码"上线。
    if [[ "$patch_count" -gt 0 ]]; then
        "$MOSGOV_SELF_DIR/apply-upstream-patches.sh" "$target" \
            || die "上游补丁应用失败（apply-upstream-patches.sh，目录 $PWD）"
        log "上游补丁已应用：$patch_count 个"
    fi

    install -d -m 0755 "$target/$MOSGOV_PLUGIN_REL"
    git -C "$mirror" archive --format=tar "$release" | tar -x -C "$target/$MOSGOV_PLUGIN_REL"
    printf '%s\n' "$release" > "$target/REVISION"
    log "源码组装完成：$target"
fi

# 静态存活探针：不含任何租户 / 数据库信息，不经过 PHP，只允许 L1 层探活。
install -d -m 0755 "$target/.well-known"
printf 'ok\n' > "$target/.well-known/mosgov-liveness"
chmod 0444 "$target/.well-known/mosgov-liveness"

plugin_meta="$target/$MOSGOV_PLUGIN_REL/plugin.json"
[[ -f "$plugin_meta" ]] || die "缺少 plugin.json"
got_ver="$(jq -r '.version' "$plugin_meta")"
[[ "$got_ver" == "$plugin_ver" ]] \
    || die "插件版本不符：期望 $plugin_ver，实际 $got_ver（拒绝部署未锁定基线）"
log "插件版本校验通过 v$got_ver"

# ChurchCRM 配置外置：Config.php 只从 env 读取凭据，发布包内不含任何秘密。
config_tpl="$tpl_dir/config/Config.php.tpl"
[[ -f "$config_tpl" ]] || config_tpl="$target/$MOSGOV_PLUGIN_REL/deploy/config/Config.php.tpl"
[[ -f "$config_tpl" ]] || die "缺少 Config.php.tpl"
install -m 0644 "$config_tpl" "$target/Include/Config.php"
log "Include/Config.php 已按 env 模板渲染（无明文口令）"

# ---- 全量 PHP 语法检查（覆盖 ChurchCRM 与插件全部 .php，不跳过 vendor） -------
syntax_log="/var/log/mosgov/syntax-${release}.log"
install -d -m 0750 -o root -g mosgov "$(dirname "$syntax_log")" 2>/dev/null || true
: > "$syntax_log"
total=0
syntax_fail=0
while IFS= read -r -d '' f; do
    total=$((total+1))
    if ! "$PHP_BIN" -l "$f" >> "$syntax_log" 2>&1; then
        syntax_fail=$((syntax_fail+1))
        printf 'SYNTAX_FAIL %s\n' "$f" >> "$syntax_log"
    fi
done < <(find "$target" -type f -name '*.php' -print0)
if [[ "$syntax_fail" -ne 0 ]]; then
    die "全量 PHP 语法检查失败：$syntax_fail/$total 个文件有误，详见 $syntax_log（未切换 symlink）"
fi
log "全量 PHP 语法检查通过：$total 个文件，0 失败"

# ---- 发布前配置闸门 ----------------------------------------------------------
verify_bin=""
if   [[ -x "$MOSGOV_SELF_DIR/verify-secrets.sh" ]]; then verify_bin="$MOSGOV_SELF_DIR/verify-secrets.sh"
elif [[ -x /usr/local/bin/mosgov-verify-secrets.sh ]]; then verify_bin=/usr/local/bin/mosgov-verify-secrets.sh
fi
mapfile -t actives < <(active_tenants)
if [[ "${#actives[@]}" -gt 0 && -n "$verify_bin" ]]; then
    "$verify_bin" --quiet >/dev/null || die "配置体检未通过，拒绝发布"
    log "配置体检通过（${#actives[@]} 个 active 教会）"
else
    warn "尚无 active 教会或缺少 verify-secrets.sh：跳过配置体检与发布后健康检查；provision-tenant.sh 之后必须补跑 mosgov-healthcheck.sh --all"
fi

# ---- 渲染各教会 vhost（模板来自 /etc/mosgov/deploy） ---------------------------
vhost_tpl="$tpl_dir/apache/mosgov-vhost.conf"
if [[ -f "$vhost_tpl" && "${#actives[@]}" -gt 0 ]]; then
    for t in "${actives[@]}"; do
        load_env "$t"
        out="/etc/apache2/sites-available/mosgov-${t}.conf"
        sed -e "s|__HOST__|$(envval MOSGOV_TENANT_HOST)|g" \
            -e "s|__TENANT_ID__|${t}|g" \
            -e "s|__TENANT_DB__|$(envval MOSGOV_TENANT_DB)|g" \
            -e "s|__TENANT_STORAGE__|$(envval MOSGOV_TENANT_STORAGE)|g" \
            -e "s|__DOCROOT__|${MOSGOV_CURRENT}|g" \
            "$vhost_tpl" > "$out"
        a2ensite "mosgov-${t}" >/dev/null 2>&1 || true
    done
    log "已渲染 ${#actives[@]} 个教会 vhost"
fi
apache2ctl configtest >/dev/null || die "Apache 配置校验失败（未切换 symlink）"

# ---- symlink 原子切换 --------------------------------------------------------
previous="$(readlink -f "$MOSGOV_CURRENT" 2>/dev/null || printf '')"
link_tmp="$(mktemp "/var/www/mos-gov/.current.XXXXXX")"
rm -f -- "$link_tmp"
ln -s "$target" "$link_tmp"
mv -Tf "$link_tmp" "$MOSGOV_CURRENT"
printf '%s\n' "$release" >> "/var/www/mos-gov/RELEASE_HISTORY"
log "symlink 已切换：$MOSGOV_CURRENT -> $target"
systemctl reload php8.4-fpm || warn "php8.4-fpm reload 失败"
systemctl reload apache2 || warn "apache2 reload 失败"

# ---- 发布后健康检查；失败自动回退 --------------------------------------------
hc=""
if   [[ -x "$MOSGOV_SELF_DIR/healthcheck.sh" ]]; then hc="$MOSGOV_SELF_DIR/healthcheck.sh"
elif [[ -x /usr/local/bin/mosgov-healthcheck.sh ]]; then hc=/usr/local/bin/mosgov-healthcheck.sh
fi
if [[ "${#actives[@]}" -gt 0 && -n "$hc" ]]; then
    hc_args=(--all)
    [[ "$no_http" -eq 1 ]] && hc_args+=(--no-http)
    if ! "$hc" "${hc_args[@]}" >> "/var/log/mosgov/deploy.log" 2>&1; then
        warn "发布后健康检查失败，自动回退到 ${previous:-（无历史版本）}"
        if [[ -n "$previous" && -d "$previous" ]]; then
            link_tmp="$(mktemp "/var/www/mos-gov/.current.XXXXXX")"
            rm -f -- "$link_tmp"
            ln -s "$previous" "$link_tmp"
            mv -Tf "$link_tmp" "$MOSGOV_CURRENT"
            systemctl reload apache2 || true
        fi
        die "DEPLOY_FAILED：已自动回退；详情见 /var/log/mosgov/deploy.log"
    fi
    log "发布后健康检查通过"
fi

# ---- 发布清单 ----------------------------------------------------------------
jq -n --arg commit "$release" --arg crm "$crm_sha" --arg crmv "$crm_ver" \
      --arg pv "$got_ver" --argjson php_files "$total" --arg date "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{schema_version:1, release_commit:$commit, deployed_at:$date,
      churchcrm:{version:$crmv, archive_sha256:$crm}, plugin_version:$pv,
      php_syntax:{files:$php_files, failed:0}, database_changed:false}' \
    > "$target/DEPLOY_MANIFEST.json"
chmod 0444 "$target/DEPLOY_MANIFEST.json"

log "DEPLOY_OK commit=$release php_files=$total db_changed=false"
printf 'DEPLOY_OK commit=%s churchcrm=%s plugin=%s php_syntax=%d/0\n' \
    "$release" "$crm_ver" "$got_ver" "$total"
