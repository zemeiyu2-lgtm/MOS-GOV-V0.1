#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 单教会私有文件存储恢复（高危，默认拒绝执行）
# 用法: mosgov-restore-files.sh --tenant church-a \
#           --file /var/backups/mosgov/church-a/files-church-a-20261115-021500.tar.gz \
#           --yes --confirm RESTORE
#
# ⚠️ 此操作非常危险，可能导致不可逆的数据丢失！
#    它会用备份归档替换当前教会的私有存储目录（上传的附件、导出文件等）。
#
# 与 restore-db.sh 同级的强制保护：
#   1. 必须显式 --yes 且 --confirm RESTORE；
#   2. 校验归档 sha256 与备份清单（manifest）归属，拒绝跨教会恢复；
#   3. 拒绝任何绝对路径 / 上跳路径的归档（防目录穿越写入 Web 根或系统目录）；
#   4. 解包到与存储同分区的临时目录，先验文件树哈希，通过后才原子切换；
#   5. 切换前把当前存储整体改名留存（pre-restore-*），恢复本身可回退；
#   6. 绝不删除备份文件，绝不触碰其他教会目录；
#   7. 恢复后跑 healthcheck --no-http。
#
# 为什么需要本脚本：backup-db.sh --with-files 早就会产出 files-*.tar.gz，
# 但仓库原先只有数据库恢复入口，文件归档没有恢复路径 —— 等于「有备份、
# 不可恢复」。Phase 8 恢复演练暴露该缺口后补齐。
# =============================================================================
set -euo pipefail
export MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"; fi

need_root
need_cmd jq
need_cmd tar
need_cmd sha256sum

tenant=""; file=""; confirmed=0; phrase=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --tenant)  tenant="${2:-}"; shift 2 ;;
        --file)    file="${2:-}"; shift 2 ;;
        --yes)     confirmed=1; shift ;;
        --confirm) phrase="${2:-}"; shift 2 ;;
        *) die "未知参数：$1" ;;
    esac
done
[[ -n "$tenant" && -n "$file" ]] || die "必须指定 --tenant 与 --file"
[[ -f "$file" ]] || die "备份归档不存在：$file"
[[ "$file" == *.tar.gz ]] || die "只接受 files-<tenant>-<stamp>.tar.gz 归档"

load_env "$tenant"
storage="$(envval MOSGOV_TENANT_STORAGE)"
[[ "$storage" == /var/lib/mosgov/* ]] || die "存储根不在 /var/lib/mosgov/ 下，拒绝操作：$storage"
[[ -d "$storage" && ! -L "$storage" ]] || die "存储根不可用或为符号链接：$storage"
sysuser="mosgov-${tenant}"
id -u "$sysuser" >/dev/null 2>&1 || die "缺少系统账号 $sysuser"

base="${file%.tar.gz}"
manifest="$base.manifest.json"
digest="$base.sha256"

echo
printf '\033[1;31m⚠️ 此操作非常危险，可能导致不可逆的数据丢失！\033[0m\n'
echo
echo "  教会 tenant      : $tenant"
echo "  将被替换的存储   : $storage（整体改名留存为 pre-restore-*）"
echo "  恢复来源         : $file"
echo "  清单文件         : $manifest"
echo "  其他教会存储     : 不受影响"
echo "  备份文件本身     : 不会被删除"
echo
[[ "$confirmed" -eq 1 && "$phrase" == "RESTORE" ]] \
    || die "未取得显式授权。必须同时传入 --yes 与 --confirm RESTORE 才会执行。"

# ---- 1. 完整性 / 归属校验 ----------------------------------------------------
if [[ -f "$digest" ]]; then
    sha256sum -c "$digest" >/dev/null || die "备份文件 sha256 校验失败，拒绝恢复"
    log "sha256 校验通过（digest）"
else
    warn "缺少 $digest，改用 manifest 中的归档哈希校验"
fi
[[ -f "$manifest" ]] || die "缺少备份清单 $manifest，无法确认归属，拒绝恢复"
jq -e '.schema_version == 1' "$manifest" >/dev/null || die "manifest 版本不符"
mtenant="$(jq -r '.tenant' "$manifest")"
[[ "$mtenant" == "$tenant" ]] \
    || die "manifest 归属不符（manifest=$mtenant，目标=$tenant），拒绝跨教会恢复"
msha="$(jq -r '.files_archive.sha256 // ""')"
[[ -n "$msha" && "$msha" == "$(sha256_of "$file")" ]] \
    || die "归档 sha256 与 manifest 不一致，拒绝恢复"
[[ "$(jq -r '.files_archive.included' "$manifest")" == "true" ]] \
    || die "该备份未包含文件归档，拒绝恢复"
want_tree="$(jq -r '.files_archive.tree_sha256 // ""')"
want_entries="$(jq -r '.files_archive.entries // 0' "$manifest")"
log "manifest 归属与哈希一致 (tenant=$tenant files=$want_entries)"

# ---- 2. 归档内容边界检查（防目录穿越） ---------------------------------------
if tar -tzf "$file" | grep -qE '(^/|(^|/)\.\.(/|$))'; then
    die "归档中存在绝对路径或上跳路径，拒绝恢复"
fi
log "归档路径边界检查通过（无绝对路径 / 无 ..）"

# ---- 3. 解包到同分区临时目录并校验文件树 -------------------------------------
parent="$(dirname "$storage")"
tmpdir="$(mktemp -d "$parent/.restore-files.XXXXXX")"
cleanup_tmp() { [[ -n "${tmpdir:-}" && -d "$tmpdir" ]] && rm -rf -- "$tmpdir"; }
trap cleanup_tmp EXIT

tar -xzf "$file" -C "$tmpdir" --no-same-owner || die "解包失败：$file"
got_entries="$(find "$tmpdir" -type f | wc -l | tr -d ' ')"
got_tree="$( ( cd "$tmpdir" && find . -type f -print0 | sort -z | xargs -0 -r sha256sum ) \
             | sha256sum | awk '{print $1}')"
[[ "$got_entries" == "$want_entries" ]] \
    || die "文件数量不符：归档 $got_entries，清单 $want_entries"
if [[ -n "$want_tree" && "$got_tree" != "$want_tree" ]]; then
    die "解包后文件树哈希与备份清单不一致（归档可能损坏），未改动当前存储"
fi
log "解包校验通过：$got_entries 个文件，树哈希 ${got_tree:0:16}…"

# ---- 4. 原子切换（当前存储整体留存为 pre-restore-*） -------------------------
stamp="$(date +%Y%m%d-%H%M%S)"
aside="${storage}.pre-restore-${stamp}"
[[ -e "$aside" ]] && die "留存目标已存在，拒绝覆盖：$aside"
mv -- "$storage" "$aside" || die "无法把当前存储改名留存"
if ! mv -- "$tmpdir" "$storage"; then
    mv -- "$aside" "$storage" || warn "回滚失败，存储留存于 $aside"
    die "切换存储失败，已回滚"
fi
tmpdir=""   # 已改名为正式目录，禁止 trap 删除
chown -R "$sysuser:mosgov" "$storage" || die "恢复后属主修正失败（存储位于 $storage）"
chmod 0700 "$storage"

final_entries="$(find "$storage" -type f | wc -l | tr -d ' ')"
final_tree="$( ( cd "$storage" && find . -type f -print0 | sort -z | xargs -0 -r sha256sum ) \
               | sha256sum | awk '{print $1}')"
if [[ "$final_entries" != "$want_entries" || ( -n "$want_tree" && "$final_tree" != "$want_tree" ) ]]; then
    warn "恢复后校验不一致（files=$final_entries tree=${final_tree:0:16}…）"
    warn "当前存储留存目录：$aside —— 可执行回退：rm -rf '$storage' && mv '$aside' '$storage'"
    die "文件恢复后校验失败"
fi
log "文件恢复完成：$final_entries 个文件，树哈希 ${final_tree:0:16}…（留存 $aside）"

# ---- 5. 恢复后健康检查 -------------------------------------------------------
hc_bin=""
if   [[ -x "$MOSGOV_SELF_DIR/healthcheck.sh" ]]; then hc_bin="$MOSGOV_SELF_DIR/healthcheck.sh"
elif [[ -x /usr/local/bin/mosgov-healthcheck.sh ]]; then hc_bin=/usr/local/bin/mosgov-healthcheck.sh
fi
if [[ -n "$hc_bin" ]]; then
    if "$hc_bin" --tenant "$tenant" --no-http >>"$MOSGOV_LOG_ROOT/restore.log" 2>&1; then
        log "恢复后健康检查通过"
    else
        die "恢复后健康检查未通过；请查阅 /var/log/mosgov/restore.log 与留存目录 $aside"
    fi
else
    warn "未找到 healthcheck.sh，恢复后未做自动验证"
fi

log "RESTORE_FILES_OK tenant=$tenant files=$final_entries tree=${final_tree:0:16}"
printf 'RESTORE_FILES_OK tenant=%s files=%s aside=%s\n' "$tenant" "$final_entries" "$aside"
