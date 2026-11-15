#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 每日全教会备份入口（由 /etc/cron.d/mosgov-backup 在 02:15 调用）
# 逐个教会独立执行，任一失败不影响其他教会；最终汇总并在有失败时以非 0 退出，
# 便于 cron / 监控告警。绝不删除任何备份文件。
# =============================================================================
set -euo pipefail
export MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"; fi

need_root
backup_bin=""
if   [[ -x "$MOSGOV_SELF_DIR/backup-db.sh" ]]; then backup_bin="$MOSGOV_SELF_DIR/backup-db.sh"
elif [[ -x /usr/local/bin/mosgov-backup-db.sh ]]; then backup_bin=/usr/local/bin/mosgov-backup-db.sh
else die "缺少 backup-db.sh"; fi

mapfile -t targets < <(active_tenants)
if [[ "${#targets[@]}" -eq 0 ]]; then
    log "没有 active 教会，无备份任务"
    exit 0
fi

total=${#targets[@]}; ok=0; failed=0
for t in "${targets[@]}"; do
    if "$backup_bin" --tenant "$t" --with-files >> "$MOSGOV_LOG_ROOT/backup-cron.log" 2>&1; then
        log "BACKUP_OK $t"; ok=$((ok+1))
    else
        warn "BACKUP_FAIL $t（详见 /var/log/mosgov/backup-cron.log）"; failed=$((failed+1))
    fi
done
log "BACKUP_ALL total=$total ok=$ok failed=$failed"
[[ "$failed" -eq 0 ]] || exit 1
exit 0
