#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 上线前配置 / 密钥体检
# 用法: mosgov-verify-secrets.sh [--tenant <id>] [--quiet]
#
# 只读检查。任何一项失败以非 0 退出，供 deploy.sh / healthcheck.sh 调用。
# 全程不打印口令、HMAC 密钥或任何配置值本身，只打印键名与指纹前 8 位。
# =============================================================================
set -euo pipefail
if [[ -f "$(dirname "$0")/mosgov-lib.sh" ]]; then
    source "$(dirname "$0")/mosgov-lib.sh"
else
    source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"
fi

need_root
need_cmd jq
need_cmd sha256sum

tenant=""
quiet=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --tenant) tenant="${2:-}"; shift 2 ;;
        --quiet)  quiet=1; shift ;;
        *) die "未知参数：$1" ;;
    esac
done

[[ -f "$MOSGOV_TENANTS_JSON" ]] || die "缺少租户登记表：$MOSGOV_TENANTS_JSON"
if [[ -n "$tenant" ]]; then targets=("$tenant"); else mapfile -t targets < <(all_tenants); fi
[[ "${#targets[@]}" -gt 0 ]] || die "租户登记表中没有任何教会"

checks=0
fail=0
ok()  { checks=$((checks+1)); [[ "$quiet" -eq 1 ]] || printf 'PASS | %s\n' "$1"; }
bad() { checks=$((checks+1)); fail=$((fail+1)); printf 'FAIL | %s\n' "$1" >&2; }
fp()  { printf '%s' "$1" | sha256sum | cut -c1-8; }
other_bits() { local m; m="$(stat -c '%a' "$1")"; printf '%d' "$(( 8#${m: -1} ))"; }

declare -A SEEN_DB_PASS=() SEEN_HMAC=() SEEN_DB=() SEEN_STORAGE=()

for t in "${targets[@]}"; do
    envfile="$MOSGOV_ETC/$t/env"
    if [[ ! -f "$envfile" ]]; then bad "$t：缺少 $envfile"; continue; fi

    owner="$(stat -c '%U:%G' "$envfile")"
    mode="$(stat -c '%a' "$envfile")"
    [[ "$owner" == "root:mosgov" ]] && ok "$t：env 属主 root:mosgov" \
        || bad "$t：env 属主是 $owner（应为 root:mosgov）"
    if (( $(other_bits "$envfile") == 0 )); then ok "$t：env 权限 $mode（其他用户无权限）"
    else bad "$t：env 权限过宽（$mode）"; fi

    load_env "$t" || { bad "$t：env 解析失败"; continue; }

    [[ "$(envval MOSGOV_DEPLOYMENT)" == "public" ]] && ok "$t：部署模式 public" \
        || bad "$t：MOSGOV_DEPLOYMENT 不是 public"
    [[ "$(envval MOS_GOV_SECURITY_MODE)" != "OFF" ]] && ok "$t：legacy gate 未设为 OFF" \
        || bad "$t：MOS_GOV_SECURITY_MODE=OFF 在公网下会被拒绝"

    proxies="$(envval MOSGOV_TRUSTED_PROXIES)"
    valid_proxy=1
    IFS=',' read -ra plist <<< "$proxies"
    for p in "${plist[@]}"; do
        [[ "$p" =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]] || valid_proxy=0
    done
    [[ "$valid_proxy" -eq 1 ]] && ok "$t：可信代理为精确 IP（无 CIDR / 通配）" \
        || bad "$t：MOSGOV_TRUSTED_PROXIES 存在非精确 IP 项"

    # 租户登记表一致性（防跨教会错配）
    [[ "$(envval MOSGOV_TENANT_DB)" == "$(tenant_field "$t" database)" ]] \
        && ok "$t：DB 名与登记表一致" || bad "$t：DB 名与登记表不一致"
    [[ "$(envval MOSGOV_TENANT_HOST)" == "$(tenant_field "$t" host)" ]] \
        && ok "$t：Host 与登记表一致" || bad "$t：Host 与登记表不一致"
    [[ "$(envval MOSGOV_TENANT_STORAGE)" == "$(tenant_field "$t" storage_root)" ]] \
        && ok "$t：存储根与登记表一致" || bad "$t：存储根与登记表不一致"

    # 密钥强度（只报长度与指纹）
    dbpass="$(envval MOSGOV_DB_PASSWORD)"
    [[ "$dbpass" =~ ^[0-9a-f]+$ && "${#dbpass}" -ge 32 ]] \
        && ok "$t：DB 口令为强随机十六进制" || bad "$t：DB 口令不符合生成规则"
    hmac="$(envval MOSGOV_AUDIT_HMAC_KEY)"
    [[ "${#hmac}" -ge 32 ]] && ok "$t：审计 HMAC 密钥长度 ${#hmac}" \
        || bad "$t：审计 HMAC 密钥不足 32 字节"
    [[ "$hmac" != *phase3-lab* ]] && ok "$t：未复用实验台 HMAC 密钥" \
        || bad "$t：复用了实验台 HMAC 密钥"
    printf 'INFO | %s：DB口令指纹=%s HMAC指纹=%s（仅指纹，不显示原值）\n' \
        "$t" "$(fp "$dbpass")" "$(fp "$hmac")"

    # 跨教会唯一性：任一教会泄露不得牵连其他教会
    for item in "DB_PASS|$dbpass" "HMAC|$hmac" \
                "DB|$(envval MOSGOV_TENANT_DB)" "STORAGE|$(envval MOSGOV_TENANT_STORAGE)"; do
        kind="${item%%|*}"; value="${item#*|}"
        case "$kind" in
            DB_PASS) prev="${SEEN_DB_PASS[$value]:-}" ;;
            HMAC)    prev="${SEEN_HMAC[$value]:-}" ;;
            DB)      prev="${SEEN_DB[$value]:-}" ;;
            *)       prev="${SEEN_STORAGE[$value]:-}" ;;
        esac
        if [[ -n "$prev" && "$prev" != "$t" ]]; then
            bad "$t：$kind 与 $prev 重复，违反教会间物理隔离"
            continue
        fi
        case "$kind" in
            DB_PASS) SEEN_DB_PASS["$value"]="$t" ;;
            HMAC)    SEEN_HMAC["$value"]="$t" ;;
            DB)      SEEN_DB["$value"]="$t" ;;
            *)       SEEN_STORAGE["$value"]="$t" ;;
        esac
    done

    # 存储根必须在 Web 根之外，且对其他用户不可读
    storage="$(envval MOSGOV_TENANT_STORAGE)"
    case "$storage" in
        /var/www/*) bad "$t：存储根位于 Web 根内，可被静态直出" ;;
        *) ok "$t：存储根在 Web 根之外" ;;
    esac
    if [[ -d "$storage" ]]; then
        if (( $(other_bits "$storage") & 5 )); then bad "$t：存储根对其他用户可读"
        else ok "$t：存储根权限 $(stat -c '%a' "$storage")"; fi
    else
        bad "$t：存储根目录不存在"
    fi

    bdir="$(envval MOSGOV_BACKUP_DIR)"
    [[ -d "$bdir" ]] && ok "$t：备份目录存在" || bad "$t：备份目录不存在"
    days="$(envval MOSGOV_BACKUP_RETENTION_DAYS)"
    [[ "$days" =~ ^[0-9]+$ && "$days" -ge 7 ]] && ok "$t：备份保留 ${days} 天" \
        || bad "$t：备份保留天数不合法"

    commit="$(envval MOSGOV_RELEASE_COMMIT)"
    is_pinned_commit "$commit" && ok "$t：发布版本锁定为 commit ${commit:0:8}" \
        || bad "$t：MOSGOV_RELEASE_COMMIT 不是 40 位十六进制 commit"

    # 公网下不允许暴露未认证的 DB 探测端点
    [[ "$(envval MOSGOV_HEALTHCHECK_PATH)" == "/.well-known/"* ]] \
        && ok "$t：健康检查为静态存活探针" \
        || bad "$t：健康检查路径应为静态 /.well-known/ 探针"

    # 占位符检查（内联，不用 lib 里会 die 的版本）
    placeholder=0
    for k in "${!MOSGOV_ENV[@]}"; do
        v="${MOSGOV_ENV[$k]}"
        if [[ "$v" =~ __[A-Z0-9_]+__ || "$v" == *"example.org"* || "$v" == *"example.com"* ]]; then
            warn "$t：$k 仍是占位符 / 示例域名"
            placeholder=1
        fi
    done
    [[ "$placeholder" -eq 0 ]] && ok "$t：无占位符与示例域名" || bad "$t：仍有占位符或示例域名"
done

printf -- '--- verify-secrets: %d 项检查，%d 项失败 ---\n' "$checks" "$fail"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
