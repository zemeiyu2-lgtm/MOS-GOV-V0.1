#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 单教会数据库恢复（高危，默认拒绝执行）
# 用法: mosgov-restore-db.sh --tenant church-a --file <backup.sql.gz> --yes --confirm RESTORE
#
# ⚠️ 此操作非常危险，可能导致不可逆的数据丢失！
#    它会 DROP 并重建当前教会的数据库，用备份内容覆盖现有全部数据。
#
# 强制保护：
#   1. 必须显式 --yes 且 --confirm RESTORE；
#   2. 先把「当前状态」另做一份时间戳备份，保证恢复本身可回退；
#   3. 用 manifest / sha256 校验备份完整性，校验失败立即停止；
#   4. 校验 dump 只包含本教会数据库，防止把 A 教会数据恢复到 B 教会；
#   5. 绝不删除备份文件本身，绝不触碰其他教会库；
#   6. 恢复后运行健康检查和证据链校验，结果写入日志。
# =============================================================================
set -euo pipefail
export MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"; fi

need_root
need_cmd jq
need_cmd gzip
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
[[ -f "$file" ]] || die "备份文件不存在：$file"

load_env "$tenant"
db="$(envval MOSGOV_TENANT_DB)"
bdir="$(envval MOSGOV_BACKUP_DIR)"
basename_noext="${file%.sql.gz}"
manifest="${basename_noext}.manifest.json"
digest="${basename_noext}.sha256"

echo
printf '\033[1;31m⚠️ 此操作非常危险，可能导致不可逆的数据丢失！\033[0m\n'
echo
echo "  教会 tenant      : $tenant"
echo "  将被覆盖的数据库 : $db（DROP DATABASE 后重建）"
echo "  恢复来源         : $file"
echo "  清单文件         : $manifest"
echo "  校验文件         : $digest"
echo "  其他教会数据库   : 不受影响（仅操作 $db）"
echo "  备份文件本身     : 不会被删除"
echo
if [[ "$confirmed" -ne 1 || "$phrase" != "RESTORE" ]]; then
    die "未取得显式授权。必须同时传入 --yes 与 --confirm RESTORE 才会执行。"
fi

# ---- 1. 完整性校验 -----------------------------------------------------------
[[ -f "$digest" ]] || die "缺少 sha256 校验文件，拒绝恢复"
sha256sum -c "$digest" >/dev/null || die "备份文件 sha256 校验失败，拒绝恢复"
log "sha256 校验通过"

if [[ -f "$manifest" ]]; then
    jq -e '.schema_version == 1' "$manifest" >/dev/null || die "manifest 版本不符"
    mtenant="$(jq -r '.tenant' "$manifest")"
    mdb="$(jq -r '.database' "$manifest")"
    [[ "$mtenant" == "$tenant" && "$mdb" == "$db" ]] \
        || die "manifest 归属不符（manifest=$mtenant/$mdb，目标=$tenant/$db），拒绝跨教会恢复"
    msha="$(jq -r '.database_dump.sha256' "$manifest")"
    [[ "$msha" == "$(sha256_of "$file")" ]] || die "manifest 与备份 sha256 不一致"
    log "manifest 归属与哈希一致 (tenant=$tenant db=$db)"
else
    warn "缺少 manifest，跳过归属比对（不推荐）"
fi

# ---- 2. dump 内容边界检查 ----------------------------------------------------
create_count="$(gzip -cd "$file" | grep -c '^CREATE DATABASE' || true)"
[[ "$create_count" == "1" ]] || die "dump 中 CREATE DATABASE 数量为 $create_count（应为 1），拒绝恢复"
# 注意：不能用 `gzip -cd | grep -q` —— pipefail 下 grep -q 提前退出会让
# gzip 收到 SIGPIPE（rc=141），即使匹配成功也判失败（dump 解压超 64KB
# 管道缓冲时必然发生，已本机复现）。改用 grep -c 全量读取。
db_matches="$(gzip -cd "$file" | grep -cE "^CREATE DATABASE .*\`${db}\`" || true)"
if [[ "$db_matches" != "1" ]]; then
    # 诊断输出：dump 实际的 CREATE DATABASE 行（仅运维排查）
    gzip -cd "$file" | grep -m1 '^CREATE DATABASE' > "$MOSGOV_LOG_ROOT/dump-header.txt" 2>/dev/null || true
    die "dump 中的数据库名与目标 $db 不符，拒绝跨教会恢复（实际行见 $MOSGOV_LOG_ROOT/dump-header.txt）"
fi
log "dump 仅包含本教会数据库 $db"

# ---- 3. 恢复前先做一份「当前状态」快照 ---------------------------------------
pre="$bdir/pre-restore-${tenant}-$(date +%Y%m%d-%H%M%S).sql.gz"
[[ -e "$pre" ]] && die "预恢复快照目标已存在，拒绝覆盖：$pre"
mysql_dump --single-transaction --routines --triggers --events \
    --hex-blob --set-charset --databases "$db" | gzip -9 > "${pre}.partial"
mv -n "${pre}.partial" "$pre"
chmod 0400 "$pre"
sha256sum "$pre" > "${pre}.sha256"
log "已保存恢复前快照：$pre（可用于回退）"

# ---- 4. 执行恢复 -------------------------------------------------------------
log "DROP / CREATE DATABASE $db ..."
mysql_admin "DROP DATABASE IF EXISTS \`${db}\`; CREATE DATABASE \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gzip -cd "$file" | mysql_root
log "导入完成"

# 数据库用户与授权定义不在 dump 内；DROP DATABASE 不会删除账号，但会连带
# 删除该库上的**表级**授权（mysql.tables_priv）。早期实现只重建了 db.* 的
# SELECT 与两张审计表的 INSERT，漏掉业务表的 INSERT/UPDATE/DELETE，
# 结果「恢复成功但教会完全不可写」。这里按 provision-tenant.sh 的同一口径
# 逐表重建，保证恢复后最小权限与可写性同时成立。
app_user="$(envval MOSGOV_DB_USER)"
c="$(root_cnf)"
mysql_admin "GRANT SELECT ON \`${db}\`.* TO '${app_user}'@'127.0.0.1';"
restore_tables="$(mariadb --defaults-extra-file="$c" -N -e \
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='${db}'
     AND TABLE_TYPE='BASE TABLE'
     AND TABLE_NAME NOT IN ('gov_evidence_log','gov_audit_log','gov_tenant','gov_tenant_file');")"
for tbl in $restore_tables; do
    mysql_admin "GRANT INSERT, UPDATE, DELETE ON \`${db}\`.\`${tbl}\` TO '${app_user}'@'127.0.0.1';"
done
mysql_admin "GRANT INSERT ON \`${db}\`.gov_evidence_log TO '${app_user}'@'127.0.0.1';
             GRANT INSERT ON \`${db}\`.gov_audit_log TO '${app_user}'@'127.0.0.1';
             FLUSH PRIVILEGES;"
log "最小权限授权已重建（业务表 DML；evidence / audit 仅 INSERT）"

# ---- 5. 恢复后验证 -----------------------------------------------------------
hc_bin=""
if   [[ -x "$MOSGOV_SELF_DIR/healthcheck.sh" ]]; then hc_bin="$MOSGOV_SELF_DIR/healthcheck.sh"
elif [[ -x /usr/local/bin/mosgov-healthcheck.sh ]]; then hc_bin=/usr/local/bin/mosgov-healthcheck.sh
fi
if [[ -n "$hc_bin" ]]; then
    if "$hc_bin" --tenant "$tenant" --no-http >>"$MOSGOV_LOG_ROOT/restore.log" 2>&1; then
        log "恢复后健康检查通过"
    else
        die "恢复后健康检查未通过；请查阅 /var/log/mosgov/restore.log 与预恢复快照 $pre"
    fi
else
    warn "未找到 healthcheck.sh，恢复后未做自动验证"
fi

log "RESTORE_OK tenant=$tenant from=$(basename "$file") pre_snapshot=$(basename "$pre")"
printf 'RESTORE_OK tenant=%s sha256=%s pre_snapshot=%s\n' "$tenant" "$(sha256_of "$file")" "$pre"
