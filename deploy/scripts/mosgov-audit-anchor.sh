#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 每日库外证据锚点（由 /etc/cron.d/mosgov-backup 在 02:35 调用）
#
# 对每个教会：
#   1. 校验整条 HMAC 证据链；链损坏时拒绝写新锚点并告警；
#   2. 与前一天锚点比对：行数只能增长；同一 last_id 的 last_hash 不得变化
#      （可发现「删库重建整条链」）；
#   3. 写时间戳锚点文件（0400，不可覆盖），并尝试同步到 COS（只 Put，不 Delete）。
# 锚点写入数据库之外，攻击者即使持有 DB 与 HMAC 密钥也无法改写历史锚点。
# =============================================================================
set -euo pipefail
export MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"; fi

need_root
need_cmd jq
PHP_BIN="${MOSGOV_PHP_BIN:-php8.4}"
command -v "$PHP_BIN" >/dev/null 2>&1 || PHP_BIN=php
need_cmd "$PHP_BIN"
anchor_php="$(mosgov_php_script audit-anchor.php)"

mapfile -t targets < <(active_tenants)
[[ "${#targets[@]}" -gt 0 ]] || { log "没有 active 教会，无锚点任务"; exit 0; }

total=${#targets[@]}; ok=0; failed=0
for t in "${targets[@]}"; do
    load_env "$t"
    adir="$(envval MOSGOV_BACKUP_DIR)/anchors"
    install -d -m 0700 -o root -g root "$adir"

    prev="$(find "$adir" -maxdepth 1 -type f -name 'anchor-*.json' -printf '%T@ %p\n' 2>/dev/null \
           | sort -rn | head -1 | cut -d' ' -f2- || true)"
    out="$(MOSGOV_PLUGIN_SRC="$MOSGOV_CURRENT/$MOSGOV_PLUGIN_REL/src/Security" \
          "$PHP_BIN" "$anchor_php" --tenant "$t" --out "$adir" 2>>"$MOSGOV_LOG_ROOT/anchor-cron.log")" || true

    if ! printf '%s' "$out" | jq -e '.ok == true' >/dev/null 2>&1; then
        warn "ANCHOR_FAIL $t：证据链校验失败或锚点写入失败（链已损坏时不写新锚点）"
        failed=$((failed+1)); continue
    fi
    newfile="$(printf '%s' "$out" | jq -r '.file')"
    new_rows="$(printf '%s' "$out" | jq -r '.anchor.rows')"
    new_id="$(printf '%s' "$out" | jq -r '.anchor.last_id')"
    new_hash="$(printf '%s' "$out" | jq -r '.anchor.last_hash')"

    if [[ -n "$prev" && -f "$prev" ]]; then
        p_rows="$(jq -r '.rows // 0' "$prev")"
        p_id="$(jq -r '.last_id // 0' "$prev")"
        p_hash="$(jq -r '.last_hash // ""' "$prev")"
        if (( new_rows < p_rows )); then
            warn "ANCHOR_ALERT $t：证据行数从 $p_rows 降到 $new_rows，疑似删库重建"
            failed=$((failed+1)); continue
        fi
        if [[ "$new_id" == "$p_id" && "$new_hash" != "$p_hash" ]]; then
            warn "ANCHOR_ALERT $t：同一 last_id 的链头哈希发生变化，疑似整链伪造"
            failed=$((failed+1)); continue
        fi
    fi

    cosenv="$MOSGOV_ETC/$t/cos.env"
    offsite="skipped"
    if [[ -f "$cosenv" ]] && command -v coscli >/dev/null 2>&1; then
        # shellcheck disable=SC1090
        set -a; source "$cosenv"; set +a
        bucket="${MOSGOV_BACKUP_COS_BUCKET:-}"
        prefix="${MOSGOV_BACKUP_COS_PREFIX:-$t}"
        if [[ -n "$bucket" ]] && coscli cp "$newfile" \
            "cos://${bucket}/${prefix}/anchors/$(basename "$newfile")" >/dev/null 2>&1; then
            offsite="ok"
        else
            offsite="failed"; warn "$t：锚点离站同步失败（本地锚点仍有效）"
        fi
    else
        warn "$t：未配置 COS，锚点仅保存在本机（防篡改强度弱于离站副本）"
    fi
    log "ANCHOR_OK $t rows=$new_rows last_id=$new_id offsite=$offsite file=$(basename "$newfile")"
    ok=$((ok+1))
done
log "ANCHOR_ALL total=$total ok=$ok failed=$failed"
[[ "$failed" -eq 0 ]] || exit 1
exit 0
