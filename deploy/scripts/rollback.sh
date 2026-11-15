#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 代码版本回滚（只切 symlink，不碰数据库）
# 用法: mosgov-rollback.sh --to <40-hex-commit> | --previous [--yes]
#
# 重要边界：
#   * 应用代码目录是同一台主机上所有教会共享的只读目录，因此回滚是「主机级」
#     操作，会影响该主机上全部教会，不是单教会操作。
#   * 绝不自动降级数据库 schema。迁移是前向的；若目标版本与当前 schema 不兼容，
#     必须另行走 restore-db.sh，不能靠回滚代码掩盖。
#   * 每次发布都由 deploy.sh 追加 /var/www/mos-gov/RELEASE_HISTORY，
#     --previous 从历史中取上一个成功发布。
# =============================================================================
set -euo pipefail
export MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"; fi

need_root
need_cmd jq

to=""
previous=0
confirmed=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --to)       to="${2:-}"; shift 2 ;;
        --previous) previous=1; shift ;;
        --yes)      confirmed=1; shift ;;
        *) die "未知参数：$1" ;;
    esac
done

history="$MOSGOV_RELEASE_ROOT/../RELEASE_HISTORY"
history="$(readlink -f "$history" 2>/dev/null || printf '%s' "/var/www/mos-gov/RELEASE_HISTORY")"
active="$(readlink -f "$MOSGOV_CURRENT" 2>/dev/null || printf '')"
[[ -n "$active" && -d "$active" ]] || die "当前发布目录无效：$MOSGOV_CURRENT"

if [[ "$previous" -eq 1 ]]; then
    [[ -f "$history" ]] || die "缺少发布历史 $history"
    current_commit="$(cat "$active/REVISION" 2>/dev/null || printf '')"
    to="$(grep -v -e "^${current_commit}$" -e '^$' "$history" | tail -1 || true)"
    [[ -n "$to" ]] || die "发布历史中没有可回退的版本"
fi

is_pinned_commit "$to" || die "必须提供 40 位十六进制 commit（禁止分支名 / HEAD / latest）"
target="$MOSGOV_RELEASE_ROOT/$to"
[[ -d "$target" ]] || die "目标发布目录不存在：$target"
[[ "$(cat "$target/REVISION" 2>/dev/null || printf '')" == "$to" ]] \
    || die "目标目录 REVISION 与指定 commit 不符，拒绝回滚"
[[ -f "$target/$MOSGOV_PLUGIN_REL/plugin.json" ]] \
    || die "目标目录缺少 MOS-GOV 插件，拒绝回滚"

echo
echo "  当前版本 : $(basename "$active")"
echo "  回退目标 : $to"
echo "  影响范围 : 本主机上全部 active 教会（共享只读代码目录）"
echo "  数据库   : 不改动，不做 schema 降级"
echo
if [[ "$confirmed" -ne 1 ]]; then
    die "回滚需要显式 --yes 确认（这是主机级操作）"
fi

link_tmp="$(mktemp "$MOSGOV_RELEASE_ROOT/../.current.XXXXXX")"
rm -f -- "$link_tmp"
ln -s "$target" "$link_tmp"
mv -Tf "$link_tmp" "$MOSGOV_CURRENT"
log "symlink 已切换：$MOSGOV_CURRENT -> $target"

apache2ctl configtest >/dev/null || die "Apache 配置校验失败"
systemctl reload apache2 || warn "apache2 reload 失败，请人工检查"
systemctl reload php8.4-fpm || warn "php8.4-fpm reload 失败，请人工检查"

hc=""
if   [[ -x "$MOSGOV_SELF_DIR/healthcheck.sh" ]]; then hc="$MOSGOV_SELF_DIR/healthcheck.sh"
elif [[ -x /usr/local/bin/mosgov-healthcheck.sh ]]; then hc=/usr/local/bin/mosgov-healthcheck.sh
fi
if [[ -n "$hc" ]] && ! "$hc" --all --no-http >>"$MOSGOV_LOG_ROOT/rollback.log" 2>&1; then
    warn "回滚后健康检查失败，自动切回 $active"
    link_tmp="$(mktemp "$MOSGOV_RELEASE_ROOT/../.current.XXXXXX")"
    rm -f -- "$link_tmp"
    ln -s "$active" "$link_tmp"
    mv -Tf "$link_tmp" "$MOSGOV_CURRENT"
    systemctl reload apache2 || true
    die "回滚失败，已自动恢复原版本；详情见 /var/log/mosgov/rollback.log"
fi

log "ROLLBACK_OK commit=$to（数据库未改动，schema 未降级）"
printf 'ROLLBACK_OK commit=%s db_changed=false\n' "$to"
