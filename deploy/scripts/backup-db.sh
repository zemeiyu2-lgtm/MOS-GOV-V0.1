#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 单教会备份（数据库 + 可选私有文件，独立目录 / 独立前缀）
# 用法: mosgov-backup-db.sh --tenant church-a [--with-files] [--prune --yes]
#
# 设计约束：
#   * 时间戳命名，绝不覆盖已有备份；
#   * 数据库 dump 与文件归档分别计算 sha256 并写入不可覆盖的 manifest；
#   * manifest 同时记录「备份时刻的证据链头」，恢复后可比对是否被整链重建；
#   * 默认只报告过期备份，不删除任何文件；只有 --prune --yes 才清理，
#     以遵守「不得覆盖 / 删除已有数据」的授权边界。
# =============================================================================
set -euo pipefail
export MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"; fi

need_root
need_cmd jq
need_cmd gzip
need_cmd sha256sum
need_cmd mariadb-dump

tenant=""
with_files=0
prune=0
confirmed=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --tenant)     tenant="${2:-}"; shift 2 ;;
        --with-files) with_files=1; shift ;;
        --prune)      prune=1; shift ;;
        --yes)        confirmed=1; shift ;;
        *) die "未知参数：$1" ;;
    esac
done
[[ -n "$tenant" ]] || die "必须指定 --tenant <id>"

load_env "$tenant"
verify_bin=""
if   [[ -f "$MOSGOV_SELF_DIR/verify-secrets.sh" ]]; then verify_bin="$MOSGOV_SELF_DIR/verify-secrets.sh"
elif [[ -x /usr/local/bin/mosgov-verify-secrets.sh ]]; then verify_bin=/usr/local/bin/mosgov-verify-secrets.sh
fi
if [[ -n "$verify_bin" ]]; then
    "$verify_bin" --tenant "$tenant" --quiet >/dev/null \
        || die "$tenant 配置体检未通过，拒绝备份（配置错误会导致备份不可用）"
fi

db="$(envval MOSGOV_TENANT_DB)"
bdir="$(envval MOSGOV_BACKUP_DIR)"
days="$(envval MOSGOV_BACKUP_RETENTION_DAYS)"
storage="$(envval MOSGOV_TENANT_STORAGE)"
mkdir -p "$bdir"
[[ -d "$bdir" ]] || die "备份目录不可用：$bdir"

stamp="$(date +%Y%m%d-%H%M%S)"
base="db-${tenant}-${stamp}"
sqlfile="$bdir/${base}.sql.gz"
manifest="$bdir/${base}.manifest.json"
digest="$bdir/${base}.sha256"

[[ -e "$sqlfile" || -e "$manifest" ]] && die "目标备份文件已存在，拒绝覆盖：$base"
partial="$(mktemp "$bdir/.${base}.partial.XXXXXX")"
chmod 0600 "$partial"
mosgov_on_exit() { [[ -f "$partial" ]] && rm -f -- "$partial"; }

log "开始备份 tenant=$tenant db=$db"
# --single-transaction 保证 InnoDB 一致性快照；triggers/events/routines 必须随备份恢复。
mysql_dump --single-transaction --routines --triggers --events \
    --hex-blob --set-charset --databases "$db" > "$partial"
gzip -9 -c "$partial" > "${partial}.gz"
mv -n "${partial}.gz" "$sqlfile"
rm -f -- "$partial"
chmod 0400 "$sqlfile"
sql_sha="$(sha256_of "$sqlfile")"
sql_bytes="$(stat -c '%s' "$sqlfile")"

files_sha=""
files_bytes=0
if [[ "$with_files" -eq 1 ]]; then
    [[ -d "$storage" && ! -L "$storage" ]] || die "私有存储目录不可用：$storage"
    tarfile="$bdir/files-${tenant}-${stamp}.tar.gz"
    [[ -e "$tarfile" ]] && die "目标归档已存在，拒绝覆盖：$tarfile"
    tar --create --gzip --file "${tarfile}.partial" --directory "$storage" . 2>/dev/null
    mv -n "${tarfile}.partial" "$tarfile"
    chmod 0400 "$tarfile"
    files_sha="$(sha256_of "$tarfile")"
    files_bytes="$(stat -c '%s' "$tarfile")"
    printf '%s  %s\n%s  %s\n' "$sql_sha" "$sqlfile" "$files_sha" "$tarfile" > "$digest"
else
    printf '%s  %s\n' "$sql_sha" "$sqlfile" > "$digest"
fi
chmod 0400 "$digest"

# 备份时刻的链头（不写锚点文件，只把状态固化进 manifest）
chain_rows=0; chain_hash=""; chain_ok=false
PHP_BIN="${MOSGOV_PHP_BIN:-php8.4}"
command -v "$PHP_BIN" >/dev/null 2>&1 || PHP_BIN=php
anchor_json="$(MOSGOV_PLUGIN_SRC="$MOSGOV_CURRENT/$MOSGOV_PLUGIN_REL/src/Security" \
    "$PHP_BIN" "$(mosgov_php_script audit-anchor.php)" --tenant "$tenant" --verify-only 2>/dev/null || true)"
if printf '%s' "$anchor_json" | jq -e '.ok == true' >/dev/null 2>&1; then
    chain_rows="$(printf '%s' "$anchor_json" | jq -r '.anchor.rows // 0')"
    chain_hash="$(printf '%s' "$anchor_json" | jq -r '.anchor.last_hash // ""')"
    chain_ok=true
else
    warn "$tenant：无法读取证据链头，manifest 中 chain_verified=false（恢复后需人工核验）"
fi

jq -n \
    --arg tenant "$tenant" --arg db "$db" --arg sql "$(basename "$sqlfile")" \
    --arg sql_sha "$sql_sha" --argjson sql_bytes "$sql_bytes" \
    --arg files_sha "$files_sha" --argjson files_bytes "$files_bytes" \
    --arg commit "$(envval MOSGOV_RELEASE_COMMIT)" \
    --arg chain_hash "$chain_hash" --argjson chain_rows "$chain_rows" \
    --argjson chain_verified "$chain_ok" --arg created "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{schema_version:1, tenant:$tenant, database:$db, created_at:$created,
      release_commit:$commit, database_dump:{file:$sql, sha256:$sql_sha, bytes:$sql_bytes},
      files_archive:{sha256:$files_sha, bytes:$files_bytes, included:($files_bytes > 0)},
      evidence_chain:{rows:$chain_rows, head_hash:$chain_hash, verified:$chain_verified},
      isolation_model:"silo-per-church", offsite_copy:"pending"}' > "$manifest"
chmod 0400 "$manifest"
sha256sum -c "$digest" >/dev/null || die "备份文件校验失败，请立即重做备份"

# 可选离站副本：只 Put，不 Delete；未配置时明确标记 skipped，不伪报成功。
offsite="skipped"
cosenv="$MOSGOV_ETC/$tenant/cos.env"
if [[ -f "$cosenv" ]] && command -v coscli >/dev/null 2>&1; then
    # shellcheck disable=SC1090
    set -a; source "$cosenv"; set +a
    prefix="${MOSGOV_BACKUP_COS_PREFIX:-$tenant}"
    bucket="${MOSGOV_BACKUP_COS_BUCKET:-}"
    if [[ -n "$bucket" ]] && coscli cp "$sqlfile" "cos://${bucket}/${prefix}/$(basename "$sqlfile")" >/dev/null 2>&1 \
       && coscli cp "$manifest" "cos://${bucket}/${prefix}/$(basename "$manifest")" >/dev/null 2>&1; then
        offsite="ok"
    else
        offsite="failed"; warn "$tenant：离站副本上传失败，本地备份仍可用"
    fi
else
    warn "$tenant：未配置 COS 凭据或 coscli，离站副本 skipped（本地备份仍有效）"
fi
tmp="$(mktemp_secure)"
jq --arg v "$offsite" '.offsite_copy = $v' "$manifest" > "$tmp"
cat "$tmp" > "$manifest"   # 保持 0400 inode 与属主，不改变文件权限

# 保留策略：默认只报告；删除必须显式 --prune --yes
cutoff=$(( $(date +%s) - days * 86400 ))
expired="$(find "$bdir" -maxdepth 1 -type f -name "db-${tenant}-*.sql.gz" \
          -printf '%T@ %p\n' 2>/dev/null | awk -v c="$cutoff" '$1 < c {print $2}')"
if [[ -n "$expired" ]]; then
    if [[ "$prune" -eq 1 && "$confirmed" -eq 1 ]]; then
        while IFS= read -r f; do
            [[ -n "$f" ]] || continue
            log "清理过期备份：$f"
            rm -f -- "$f" "$f.manifest.json" 2>/dev/null || true
        done <<< "$expired"
    else
        log "发现 $(printf '%s\n' "$expired" | wc -l) 个超过 ${days} 天的备份，未删除（需 --prune --yes 显式授权）"
    fi
fi

log "备份完成：$(basename "$sqlfile") sha256=${sql_sha:0:16}… bytes=$sql_bytes offsite=$offsite"
printf 'BACKUP_OK tenant=%s file=%s sha256=%s offsite=%s\n' "$tenant" "$sqlfile" "$sql_sha" "$offsite"
