#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 部署脚本公共库（由 /usr/local/bin/mosgov-*.sh 引用）
#
# 安全约定：
#   * env 文件只做 KEY=VALUE 逐行解析并存入关联数组，绝不使用 source / eval，
#     防止配置文件被当成 shell 代码执行。
#   * 数据库口令只写入 0600 临时 option file，绝不出现在命令行、环境变量或日志。
#   * 生成的所有口令均为纯十六进制，避免 option file 中 # / 空格 / 引号的转义问题。
# =============================================================================
set -euo pipefail

MOSGOV_ETC="${MOSGOV_ETC:-/etc/mosgov}"
MOSGOV_TENANTS_JSON="${MOSGOV_TENANTS_JSON:-$MOSGOV_ETC/tenants.json}"
MOSGOV_RELEASE_ROOT="${MOSGOV_RELEASE_ROOT:-/var/www/mos-gov/releases}"
MOSGOV_CURRENT="${MOSGOV_CURRENT:-/var/www/mos-gov/current}"
MOSGOV_SHARED="${MOSGOV_SHARED:-/var/www/mos-gov/shared}"
MOSGOV_BACKUP_ROOT="${MOSGOV_BACKUP_ROOT:-/var/backups/mosgov}"
MOSGOV_LOG_ROOT="${MOSGOV_LOG_ROOT:-/var/log/mosgov}"
MOSGOV_LIB_DIR="${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}"
MOSGOV_PLUGIN_REL="plugins/community/mos-gov"
# lib.sh 所在的真实目录；生产环境里 cli.php / *.php 与 lib.sh 同目录。
MOSGOV_LIB_FS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MOSGOV_SELF_DIR="${MOSGOV_SELF_DIR:-$MOSGOV_LIB_FS_DIR}"

# 定位随附的 PHP 运维脚本（仓库内或已安装位置）。
mosgov_php_script() {
    local name="$1"
    if [[ -f "$MOSGOV_LIB_FS_DIR/$name" ]]; then printf '%s' "$MOSGOV_LIB_FS_DIR/$name"
    elif [[ -f "$MOSGOV_SELF_DIR/$name" ]]; then printf '%s' "$MOSGOV_SELF_DIR/$name"
    elif [[ -f "$MOSGOV_LIB_DIR/$name" ]]; then printf '%s' "$MOSGOV_LIB_DIR/$name"
    else die "缺少运维脚本：$name"; fi
}

log()  { printf '[mosgov] %s\n' "$*"; }
warn() { printf '[mosgov][WARN] %s\n' "$*" >&2; }
die()  { printf '[mosgov][FAIL] %s\n' "$*" >&2; exit 1; }
need_root() { [[ "$(id -u)" -eq 0 ]] || die "必须以 root 运行：$0"; }
need_cmd()  { command -v "$1" >/dev/null 2>&1 || die "缺少命令：$1"; }

# ---- 临时文件统一登记 / 清理 -------------------------------------------------
declare -ga MOSGOV_TMP_FILES=()
mktemp_secure() {
    local f
    f="$(mktemp "${TMPDIR:-/tmp}/mosgov.XXXXXXXX")"
    chmod 0600 "$f"
    MOSGOV_TMP_FILES+=("$f")
    printf '%s' "$f"
}
mosgov_cleanup() {
    # set -e 会令 EXIT trap 内任何「末命令失败」直接中止函数并改变脚本退出码
    # （backup 输出 BACKUP_OK 后仍 rc=1 的根因，已本机复现）。
    # trap 内所有语句一律 if 化，保证到达 return "$rc"。
    local rc=$?
    local f
    for f in "${MOSGOV_TMP_FILES[@]:-}"; do
        if [[ -n "$f" && -f "$f" ]]; then rm -f -- "$f"; fi
    done
    declare -F mosgov_on_exit >/dev/null 2>&1 && mosgov_on_exit || true
    return "$rc"
}
trap mosgov_cleanup EXIT

# ---- env 安全加载 ------------------------------------------------------------
# 只读解析，结果放进 MOSGOV_ENV 关联数组；用 envval <KEY> 取值。
declare -gA MOSGOV_ENV=()
load_env() {
    local tenant="$1" file line key val
    [[ "$tenant" =~ ^[a-z0-9][a-z0-9-]{0,30}[a-z0-9]$ ]] \
        || die "非法 tenant id：$tenant"
    file="$MOSGOV_ETC/$tenant/env"
    [[ -f "$file" ]] || die "缺少环境文件：$file"

    MOSGOV_ENV=()
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%$'\r'}"
        [[ -z "$line" || "$line" == \#* ]] && continue
        [[ "$line" == *=* ]] || die "env 文件存在非法行（$file）"
        key="${line%%=*}"
        val="${line#*=}"
        [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || die "env 文件存在非法键名：$key"
        MOSGOV_ENV["$key"]="$val"
    done < "$file"

    local k required=(
        MOSGOV_DEPLOYMENT MOS_GOV_SECURITY_MODE MOSGOV_TRUSTED_PROXIES
        MOSGOV_TENANT_ID MOSGOV_TENANT_DB MOSGOV_TENANT_HOST MOSGOV_TENANT_STORAGE
        MOSGOV_DB_HOST MOSGOV_DB_PORT MOSGOV_DB_USER MOSGOV_DB_PASSWORD
        MOSGOV_AUDIT_HMAC_KEY MOSGOV_BACKUP_DIR MOSGOV_BACKUP_RETENTION_DAYS
        MOSGOV_RELEASE_COMMIT MOSGOV_HEALTHCHECK_PATH
    )
    for k in "${required[@]}"; do
        [[ -n "${MOSGOV_ENV[$k]:-}" ]] || die "$file 缺少必需项：$k"
    done
    [[ "${MOSGOV_ENV[MOSGOV_TENANT_ID]}" == "$tenant" ]] \
        || die "$file 中 MOSGOV_TENANT_ID 与目录名不一致（跨教会错配，fail closed）"
}
envval() { printf '%s' "${MOSGOV_ENV[$1]:-}"; }

# ---- 租户登记表 --------------------------------------------------------------
active_tenants() {
    [[ -f "$MOSGOV_TENANTS_JSON" ]] || { warn "未找到租户登记表 $MOSGOV_TENANTS_JSON"; return 0; }
    need_cmd jq
    jq -r '.tenants[] | select(.status == "active") | .tenant_id' "$MOSGOV_TENANTS_JSON"
}
all_tenants() {
    [[ -f "$MOSGOV_TENANTS_JSON" ]] || return 0
    need_cmd jq
    jq -r '.tenants[].tenant_id' "$MOSGOV_TENANTS_JSON"
}
tenant_field() { # tenant_field <tenant> <json-key>
    need_cmd jq
    jq -r --arg id "$1" --arg k "$2" \
        '.tenants[] | select(.tenant_id == $id) | .[$k] // empty' "$MOSGOV_TENANTS_JSON"
}
tenant_status() {
    need_cmd jq
    jq -r --arg id "$1" \
        '.tenants[] | select(.tenant_id == $id) | .status // empty' "$MOSGOV_TENANTS_JSON"
}

# ---- 数据库客户端凭据 --------------------------------------------------------
# root：优先 unix_socket 免密（Ubuntu 默认），否则读取 0600 root 专用 option file。
# 绝不把 root 口令写进命令行、进程环境或本脚本。
declare -g MOSGOV_ROOT_CNF=""
root_cnf() {
    if [[ -n "$MOSGOV_ROOT_CNF" ]]; then printf '%s' "$MOSGOV_ROOT_CNF"; return 0; fi
    if mariadb --protocol=SOCKET -u root -e 'SELECT 1' >/dev/null 2>&1; then
        MOSGOV_ROOT_CNF="$(mktemp_secure)"
        printf '[client]\nprotocol=SOCKET\nuser=root\n' > "$MOSGOV_ROOT_CNF"
    elif [[ -r "$MOSGOV_ETC/.dbroot.cnf" ]]; then
        local mode
        mode="$(stat -c '%a' "$MOSGOV_ETC/.dbroot.cnf")"
        [[ "$mode" == "600" || "$mode" == "400" ]] \
            || die "$MOSGOV_ETC/.dbroot.cnf 权限必须为 0600/0400，当前 $mode"
        MOSGOV_ROOT_CNF="$MOSGOV_ETC/.dbroot.cnf"
    else
        die "无法取得数据库管理凭据：unix_socket 认证不可用且缺少 $MOSGOV_ETC/.dbroot.cnf"
    fi
    printf '%s' "$MOSGOV_ROOT_CNF"
}
mysql_root()   { local c; c="$(root_cnf)"; mariadb      --defaults-extra-file="$c" "$@"; }
mysql_dump()   { local c; c="$(root_cnf)"; mariadb-dump --defaults-extra-file="$c" "$@"; }
mysql_admin()  { mysql_root -e "$1"; }

# 应用账号：从 env 取，写入 0600 临时 option file。
app_cnf() {
    local f
    f="$(mktemp_secure)"
    {
        printf '[client]\n'
        printf 'host=%s\n'     "$(envval MOSGOV_DB_HOST)"
        printf 'port=%s\n'     "$(envval MOSGOV_DB_PORT)"
        printf 'user=%s\n'     "$(envval MOSGOV_DB_USER)"
        printf 'password=%s\n' "$(envval MOSGOV_DB_PASSWORD)"
    } > "$f"
    printf '%s' "$f"
}
mysql_app() { local c; c="$(app_cnf)"; mariadb --defaults-extra-file="$c" "$(envval MOSGOV_TENANT_DB)" "$@"; }

# ---- 通用校验 ----------------------------------------------------------------
is_pinned_commit() { [[ "$1" =~ ^[0-9a-f]{40}$ ]]; }
sha256_of() { sha256sum "$1" | awk '{print $1}'; }

# 禁止把占位符配置带进生产；只判断模式，不回显值本身。
assert_no_placeholders() {
    local tenant="$1" k v bad=0
    for k in "${!MOSGOV_ENV[@]}"; do
        v="${MOSGOV_ENV[$k]}"
        if [[ "$v" =~ __[A-Z0-9_]+__ || "$v" == *"example.org"* || "$v" == *"example.com"* ]]; then
            warn "$tenant：$k 仍是占位符 / 示例域名，禁止上线"
            bad=1
        fi
    done
    [[ "$bad" -eq 0 ]] || die "$tenant 存在未替换的配置占位符"
}

# 只有纯十六进制口令才允许（与 provision-tenant.sh 生成规则一致）
assert_hex_secret() {
    local tenant="$1" key="$2" minlen="$3" v
    v="$(envval "$key")"
    [[ "$v" =~ ^[0-9a-f]+$ && "${#v}" -ge "$minlen" ]] \
        || die "$tenant：$key 必须是至少 $minlen 位纯十六进制随机值（不得含特殊字符）"
}
