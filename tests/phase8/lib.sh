#!/usr/bin/env bash
# =============================================================================
# MOS-GOV Phase 8 —— Linux 部署验证共用辅助（仅限隔离实验机）
#
# 提供：断言计数、root SQL、真实 HTTP（Host 头 + 手工 Cookie 罐）、
#      数据库/文件快照。
#
# 为什么手工管理 Cookie：生产 vhost 设置了 SetEnv HTTPS on，PHP 会把会话
# Cookie 标成 Secure；而实验室入口是回环 8080 明文（隧道模式设计如此），
# curl 的 cookie jar 不会把 Secure Cookie 回发到 http://。手工解析
# Set-Cookie 并回填 Cookie 头，才能在不引入本机 TLS 的前提下验证真实会话。
# =============================================================================

P8_PASS=0; P8_FAIL=0; P8_SKIP=0
P8_BASE="${P8_BASE:-http://127.0.0.1:8080}"
P8_HOST=""
P8_WORK="${P8_WORK:-/var/tmp/mosgov-p8}"
mkdir -p "$P8_WORK"
P8_COOKIES="$P8_WORK/cookies.txt"
P8_BODY="$P8_WORK/last.body"
P8_HDR="$P8_WORK/last.hdr"
: > "$P8_COOKIES"

p8_ok()   { P8_PASS=$((P8_PASS + 1)); printf '  [PASS] %s\n' "$*"; }
p8_bad()  { P8_FAIL=$((P8_FAIL + 1)); printf '  [FAIL] %s\n' "$*"; }
p8_skip() { P8_SKIP=$((P8_SKIP + 1)); printf '  [SKIP] %s\n' "$*"; }
p8_hdr()  { printf '\n== %s ==\n' "$*"; }
p8_info() { printf '  [INFO] %s\n' "$*"; }
p8_warn() { printf '  [WARN] %s\n' "$*"; }

p8_eq()   { local d="$1" w="$2" g="$3"; [[ "$g" == "$w" ]] && p8_ok "$d ($g)" || p8_bad "$d：期望 [$w] 实际 [$g]"; }
p8_ne()   { local d="$1" n="$2" g="$3"; [[ "$g" != "$n" ]] && p8_ok "$d ($g)" || p8_bad "$d：不应为 [$n]"; }
p8_has()  { local d="$1" n="$2" t="$3"; [[ "$t" == *"$n"* ]] && p8_ok "$d" || p8_bad "$d：未包含 [$n]"; }
p8_ge()   { local d="$1" n="$2" g="$3"; [[ "${g:-0}" =~ ^[0-9]+$ ]] && (( g >= n )) && p8_ok "$d ($g >= $n)" || p8_bad "$d：$g < $n"; }
p8_file() { local d="$1" f="$2"; [[ -f "$f" ]] && p8_ok "$d" || p8_bad "$d：缺少 $f"; }
p8_dir()  { local d="$1" f="$2"; [[ -d "$f" ]] && p8_ok "$d" || p8_bad "$d：缺少 $f"; }
p8_exit() { local d="$1" want="$2" got="$3"; [[ "$got" == "$want" ]] && p8_ok "$d (exit=$got)" || p8_bad "$d：期望 exit=$want 实际 exit=$got"; }

# ---- root SQL（unix_socket 免密） -------------------------------------------
sqlr() { mariadb --protocol=SOCKET -u root -N -B -e "$1" 2>/dev/null; }
sqlq() { sqlr "$1" | head -1; }

# ---- Cookie 罐 ---------------------------------------------------------------
ck_reset() { : > "$P8_COOKIES"; }

ck_load() {
    [[ -s "$P8_COOKIES" ]] || return 0
    awk '{ printf "%s; ", $0 }' "$P8_COOKIES"
}

ck_store() { # ck_store <header-file>
    local hdr="$1" line name val tmp
    [[ -f "$hdr" ]] || return 0
    while IFS= read -r line; do
        line="${line%$'\r'}"
        case "$line" in
            [Ss]et-[Cc]ookie:*) ;;
            *) continue ;;
        esac
        line="${line#*: }"
        line="${line%%;*}"
        name="${line%%=*}"
        val="${line#*=}"
        [[ -n "$name" && -n "$val" ]] || continue
        tmp="$(mktemp "$P8_WORK/ck.XXXXXX")"
        awk -v n="$name" -v v="$val" '
            BEGIN { done = 0 }
            index($0, n "=") == 1 { print n "=" v; done = 1; next }
            { print }
            END { if (!done) print n "=" v }
        ' "$P8_COOKIES" > "$tmp"
        mv -f "$tmp" "$P8_COOKIES"
    done < "$hdr"
}

# p8_req <METHOD> <PATH> [额外 curl 参数...] → 打印 HTTP 状态码
p8_req() {
    local method="$1" path="$2"; shift 2
    local ck code
    ck="$(ck_load)"
    local args=(-sS -o "$P8_WORK/body" -D "$P8_WORK/hdr" -X "$method"
                --max-time 30 --noproxy '*'
                -H "Host: ${P8_HOST}"
                -H "Accept: text/html,application/xhtml+xml"
                -A "MOSGOV-P8-Verify/1.0")
    [[ -n "$ck" ]] && args+=(-H "Cookie: ${ck%; }")
    code="$(curl "${args[@]}" "$@" -w '%{http_code}' "${P8_BASE}${path}" 2>"$P8_WORK/curlerr")" || code="000"
    ck_store "$P8_WORK/hdr"
    cp -f "$P8_WORK/body" "$P8_BODY" 2>/dev/null || true
    cp -f "$P8_WORK/hdr"  "$P8_HDR"  2>/dev/null || true
    printf '%s' "$code"
}

p8_loc() { awk 'BEGIN { IGNORECASE = 1 } /^Location:/ { print $2 }' "$P8_HDR" 2>/dev/null | tr -d '\r' | tail -1; }

# 生产 Config.php 把 $URL 固定成 https://<host>/，跳转会带绝对地址；
# 实验室只监听回环 8080，所以取 Location 的 path 部分再自己拼 Host 头。
p8_loc_path() {
    local l; l="$(p8_loc)"
    [[ -n "$l" ]] || { printf ''; return 0; }
    case "$l" in
        http://*|https://*)
            l="${l#*://}"
            case "$l" in */*) l="/${l#*/}" ;; *) l="/" ;; esac
            ;;
    esac
    printf '%s' "$l"
}

# ---- 快照 --------------------------------------------------------------------
db_snapshot() { # db_snapshot <db> <out-file>
    local db="$1" out="$2" t
    : > "$out"
    for t in $(sqlr "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA='$db' AND TABLE_TYPE='BASE TABLE'
                     ORDER BY TABLE_NAME"); do
        printf '%s\t%s\n' "$t" "$(sqlq "SELECT COUNT(*) FROM \`$db\`.\`$t\`")" >> "$out"
    done
}

file_snapshot() { # file_snapshot <dir> <out-file>
    local dir="$1" out="$2"
    if [[ -d "$dir" ]]; then
        ( cd "$dir" && find . -type f -print0 | sort -z | xargs -0 -r sha256sum ) > "$out" 2>/dev/null
    else
        : > "$out"
    fi
    printf '%s' "$(wc -l < "$out")"
}
