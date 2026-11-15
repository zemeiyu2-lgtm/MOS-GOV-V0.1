#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 健康检查（分层，不做「能打开网页就算通过」）
# 用法: mosgov-healthcheck.sh --tenant church-a | --all [--no-http] [--json]
#
#   L1 传输层：HTTPS + 静态存活探针（不经 PHP / DB）
#   L2 应用层：PHP CLI 检查 DB 归属、租户边界、最小权限、证据链、存储、版本锁
#   L3 基础设施：PHP-FPM socket、Apache 配置、磁盘余量、锚点新鲜度
#   任一层失败即 exit 1。deploy.sh 用它做发布后闸门，失败会自动回滚 symlink。
# =============================================================================
set -euo pipefail
export MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then
    source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else
    source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"
fi

need_root

tenant=""
all=0
no_http=0
as_json=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --tenant)  tenant="${2:-}"; shift 2 ;;
        --all)     all=1; shift ;;
        --no-http) no_http=1; shift ;;
        --json)    as_json=1; shift ;;
        *) die "未知参数：$1" ;;
    esac
done

if [[ "$all" -eq 1 || -z "$tenant" ]]; then
    mapfile -t targets < <(active_tenants)
else
    targets=("$tenant")
fi
[[ "${#targets[@]}" -gt 0 ]] || die "没有处于 active 状态的教会（先运行 provision-tenant.sh）"

PHP_BIN="${MOSGOV_PHP_BIN:-php8.4}"
command -v "$PHP_BIN" >/dev/null 2>&1 || PHP_BIN=php
need_cmd "$PHP_BIN"
need_cmd jq

checks=0
fail=0
ok()  { checks=$((checks+1)); [[ "$as_json" -eq 1 ]] || printf 'PASS | %s\n' "$1"; }
bad() { checks=$((checks+1)); fail=$((fail+1)); printf 'FAIL | %s\n' "$1" >&2; }

for t in "${targets[@]}"; do
    load_env "$t"
    host="$(envval MOSGOV_TENANT_HOST)"
    hpath="$(envval MOSGOV_HEALTHCHECK_PATH)"

    # ---- L1: HTTPS 存活探针 --------------------------------------------------
    if [[ "$no_http" -eq 0 ]]; then
        need_cmd curl
        # TLS 在本机终结（见 apache/mosgov-vhost.conf），用 --resolve 避免依赖外部 DNS。
        code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 \
            --noproxy '*' --resolve "${host}:443:127.0.0.1" \
            "https://${host}${hpath}" 2>/dev/null || printf '000')"
        [[ "$code" == "200" ]] && ok "$t：L1 HTTPS 存活探针 200" \
            || bad "$t：L1 HTTPS 存活探针返回 $code（TLS / vhost / 探针文件未就绪）"
        # 同时确认 HTTP 不会明文提供应用内容
        plain="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 \
            --noproxy '*' --resolve "${host}:80:127.0.0.1" \
            "http://${host}/" 2>/dev/null || printf '000')"
        [[ "$plain" == "301" || "$plain" == "302" || "$plain" == "403" || "$plain" == "000" ]] \
            && ok "$t：L1 HTTP 未明文提供应用（$plain）" \
            || bad "$t：L1 HTTP 明文返回 $plain，必须重定向到 HTTPS"
    fi

    # ---- L2: PHP CLI 运行时检查 ---------------------------------------------
    hc="$(mosgov_php_script healthcheck.php)"
    out="$(MOSGOV_PLUGIN_SRC="$MOSGOV_CURRENT/$MOSGOV_PLUGIN_REL/src/Security" \
          "$PHP_BIN" "$hc" --tenant "$t" --env "$MOSGOV_ETC/$t/env" 2>>"$MOSGOV_LOG_ROOT/healthcheck.log")" || true
    if printf '%s' "$out" | jq -e '.ok == true' >/dev/null 2>&1; then
        rows="$(printf '%s' "$out" | jq -r '.anchor.rows // 0')"
        ok "$t：L2 运行时全部通过（证据链 ${rows} 行）"
    else
        bad "$t：L2 运行时检查失败（详见 /var/log/mosgov/healthcheck.log）"
        printf '%s\n' "$out" | jq -r '.checks[]? | select(.ok == false) | "       - \(.name): \(.detail)"' >&2 || true
    fi

    # ---- L3: 基础设施 --------------------------------------------------------
    sock="/run/php/php8.4-fpm-mosgov-${t}.sock"
    [[ -S "$sock" ]] && ok "$t：L3 独立 PHP-FPM socket 存在" || bad "$t：L3 缺少 PHP-FPM socket $sock"
    if apache2ctl configtest >/dev/null 2>&1; then ok "$t：L3 apache2ctl configtest 通过"
    else bad "$t：L3 apache2ctl configtest 失败"; fi

    bdir="$(envval MOSGOV_BACKUP_DIR)"
    free_kb="$(df -Pk "$bdir" 2>/dev/null | awk 'NR==2 {print $4}')"
    [[ "${free_kb:-0}" =~ ^[0-9]+$ && "$free_kb" -ge 1048576 ]] \
        && ok "$t：L3 备份卷余量 $((free_kb/1024)) MiB" || bad "$t：L3 备份卷余量不足 1 GiB"

    anchor_dir="$bdir/anchors"
    newest="$(find "$anchor_dir" -maxdepth 1 -type f -name 'anchor-*.json' -printf '%T@ %p\n' 2>/dev/null \
             | sort -rn | head -1 | awk '{print $1}')"
    if [[ -z "${newest:-}" ]]; then
        bad "$t：L3 尚无库外证据锚点（先运行 mosgov-audit-anchor.sh）"
    elif (( $(printf '%.0f' "$newest") + 129600 < $(date +%s) )); then
        bad "$t：L3 最新锚点超过 36 小时，库外锚定已失效"
    else
        ok "$t：L3 库外证据锚点在 36 小时内"
    fi
done

printf -- '--- healthcheck: %d 项检查，%d 项失败 ---\n' "$checks" "$fail"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
