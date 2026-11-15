#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 新建教会（silo-per-church 物理隔离）
# 用法: mosgov-provision-tenant.sh --tenant church-a [--dry-run]
#
# 为每个教会创建：独立数据库 + 独立最小权限 DB 账号 + 独立系统账号 +
# 独立 PHP-FPM pool + 独立 vhost + 独立私有存储 + 独立备份前缀 + 独立 HMAC 密钥。
# 共享的只有只读应用代码目录（$MOSGOV_CURRENT）。
#
# 明确不做的事：
#   * 不导入任何真实教会成员 / 奉献数据（本脚本没有导入入口）；
#   * 不提供跨教会超级管理员；
#   * 不自动申请证书（certbot 需要域名解析与人工确认，见脚本末尾提示）；
#   * 不修改其他教会的配置或数据库。
# =============================================================================
set -euo pipefail
export MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"; fi

need_root
for c in jq openssl mariadb apache2ctl; do need_cmd "$c"; done
PHP_BIN="${MOSGOV_PHP_BIN:-php8.4}"
command -v "$PHP_BIN" >/dev/null 2>&1 || PHP_BIN=php

tenant=""; dry=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --tenant)  tenant="${2:-}"; shift 2 ;;
        --dry-run) dry=1; shift ;;
        *) die "未知参数：$1" ;;
    esac
done
[[ -n "$tenant" ]] || die "必须指定 --tenant <id>"
[[ -f "$MOSGOV_TENANTS_JSON" ]] || die "缺少租户登记表 $MOSGOV_TENANTS_JSON"

host="$(tenant_field "$tenant" host)"
db="$(tenant_field "$tenant" database)"
dbuser="$(tenant_field "$tenant" db_user)"
storage="$(tenant_field "$tenant" storage_root)"
prefix="$(tenant_field "$tenant" backup_prefix)"
display="$(tenant_field "$tenant" display_name)"
status="$(tenant_status "$tenant")"

[[ -n "$host" && -n "$db" && -n "$dbuser" && -n "$storage" ]] \
    || die "登记表中找不到教会 $tenant 的完整定义"
[[ "$host" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] \
    || die "Host 不是合法域名：$host（示例域名 / 占位值不予受理）"
[[ "$host" != *example.* && "$host" != *".test" && "$host" != *".local" ]] \
    || die "Host 是示例 / 保留域名，禁止上线：$host"
[[ "$db" =~ ^[a-z0-9_]+$ && "$dbuser" =~ ^[a-z0-9_]+$ ]] \
    || die "数据库名 / 账号含非法字符"
[[ "$storage" == /var/lib/mosgov/* ]] || die "存储根必须位于 /var/lib/mosgov/ 下"
[[ "$status" == "planned" || "$status" == "provisioning" ]] \
    || die "教会 $tenant 当前状态为 $status；仅 planned/provisioning 可初始化（防止覆盖已运行教会）"

echo "  教会 tenant : $tenant"
echo "  域名        : $host"
echo "  独立数据库  : $db"
echo "  独立账号    : $dbuser"
echo "  私有存储    : $storage"
echo "  备份前缀    : $prefix"
if [[ "$dry" -eq 1 ]]; then
    log "DRY-RUN：以上资源均未创建。"
    exit 0
fi

# ---- 幂等状态：先标记 provisioning，避免中途失败被误认为已可用 ----------------
regtmp="$(mktemp_secure)"
jq --arg id "$tenant" '(.tenants[] | select(.tenant_id == $id) | .status) = "provisioning"' \
    "$MOSGOV_TENANTS_JSON" > "$regtmp"
cat "$regtmp" > "$MOSGOV_TENANTS_JSON"
chmod 0640 "$MOSGOV_TENANTS_JSON"

# ---- 1. 独立系统账号与目录 ---------------------------------------------------
sysuser="mosgov-${tenant}"
if ! id -u "$sysuser" >/dev/null 2>&1; then
    useradd --system --shell /usr/sbin/nologin --gid mosgov \
        --home-dir "/var/lib/mosgov/${tenant}" "$sysuser"
fi
install -d -m 0750 -o "$sysuser" -g mosgov "$storage"
install -d -m 0750 -o root -g mosgov "/etc/mosgov/${tenant}"
install -d -m 0750 -o root -g mosgov "/var/log/mosgov/${tenant}"
install -d -m 0700 -o root -g root  "/var/backups/mosgov/${tenant}"
install -d -m 0700 -o root -g root  "/var/backups/mosgov/${tenant}/anchors"
install -d -m 0750 -o root -g mosgov "/var/lib/mosgov/${tenant}"
log "独立目录与系统账号就绪"

# ---- 2. 独立数据库与最小权限账号 ---------------------------------------------
dbpass="$(openssl rand -hex 32)"
hmac="$(openssl rand -hex 32)"
c="$(root_cnf)"

# 先确认目标库不存在，避免覆盖其他教会 / 已有数据
exists="$(mariadb --defaults-extra-file="$c" -N -e \
    "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${db}';" 2>/dev/null)"
[[ "$exists" == "0" ]] || die "数据库 $db 已存在，拒绝覆盖（如需重建须先人工授权）"

mariadb --defaults-extra-file="$c" -e \
    "CREATE DATABASE \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     CREATE USER IF NOT EXISTS '${dbuser}'@'127.0.0.1' IDENTIFIED BY '${dbpass}';
     GRANT SELECT ON \`${db}\`.* TO '${dbuser}'@'127.0.0.1';
     FLUSH PRIVILEGES;"
log "独立空数据库 $db 已创建（utf8mb4）"

# 依次执行 6 个迁移；005/006 内的 DELIMITER 由 mariadb CLI 解析。
sqlroot="$MOSGOV_CURRENT/$MOSGOV_PLUGIN_REL/database"
[[ -d "$sqlroot" ]] || die "找不到迁移目录 $sqlroot（请先执行 mosgov-deploy.sh --release <commit>）"
for f in 001_initial 002_v02_authorization 003_healthboard \
         004_healthboard_remediation 005_governance_evidence 006_tenant_boundary; do
    [[ -f "$sqlroot/$f.sql" ]] || die "缺少迁移文件 $f.sql"
    mariadb --defaults-extra-file="$c" "$db" < "$sqlroot/$f.sql"
    log "  迁移 $f.sql OK"
done

# 业务表授予增删改；审计 / 证据表仅 INSERT，租户表完全不给写权限。
tables="$(mariadb --defaults-extra-file="$c" -N -e \
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='${db}'
     AND TABLE_TYPE='BASE TABLE'
     AND TABLE_NAME NOT IN ('gov_evidence_log','gov_audit_log','gov_tenant','gov_tenant_file');" 2>/dev/null)"
for tbl in $tables; do
    mariadb --defaults-extra-file="$c" -e \
        "GRANT INSERT, UPDATE, DELETE ON \`${db}\`.\`${tbl}\` TO '${dbuser}'@'127.0.0.1';"
done
mariadb --defaults-extra-file="$c" -e \
    "GRANT INSERT ON \`${db}\`.gov_evidence_log TO '${dbuser}'@'127.0.0.1';
     GRANT INSERT ON \`${db}\`.gov_audit_log TO '${dbuser}'@'127.0.0.1';
     FLUSH PRIVILEGES;"
log "最小权限授权完成（evidence/audit 仅 SELECT + INSERT）"

# gov_tenant singleton 行：运行时 fail-closed 校验的锚点
esc() { printf '%s' "$1" | sed "s/'/''/g"; }
seed="$(mktemp_secure)"
{
    printf 'INSERT INTO gov_tenant (singleton,tenant_id,tenant_db,host,display_name,status)\n'
    printf "VALUES (1,'%s','%s','%s','%s','active')\n" \
        "$(esc "$tenant")" "$(esc "$db")" "$(esc "$host")" "$(esc "$display")"
    printf 'ON DUPLICATE KEY UPDATE tenant_id=VALUES(tenant_id), tenant_db=VALUES(tenant_db),\n'
    printf '  host=VALUES(host), display_name=VALUES(display_name), status=VALUES(status);\n'
} > "$seed"
mariadb --defaults-extra-file="$c" "$db" < "$seed"
log "gov_tenant singleton 已写入并置为 active"

# ---- 3. 生成独立 env（0640 root:mosgov，绝不含示例值） -------------------------
envtpl="$MOSGOV_ETC/deploy/env/mosgov.env.example"
[[ -f "$envtpl" ]] || envtpl="$MOSGOV_SELF_DIR/../env/mosgov.env.example"
[[ -f "$envtpl" ]] || die "缺少 env 模板"
release_commit="$(cat "$MOSGOV_CURRENT/REVISION" 2>/dev/null || printf '')"
is_pinned_commit "$release_commit" || die "当前发布缺少有效 REVISION，无法写入版本锁"

envfile="$MOSGOV_ETC/${tenant}/env"
[[ -e "$envfile" ]] && die "env 文件已存在，拒绝覆盖：$envfile"
sed -e "s|__TENANT_ID__|${tenant}|g" \
    -e "s|__TENANT_DB__|${db}|g" \
    -e "s|__TENANT_HOST__|${host}|g" \
    -e "s|__TENANT_STORAGE__|${storage}|g" \
    -e "s|__DB_USER__|${dbuser}|g" \
    -e "s|__DB_PASSWORD__|${dbpass}|g" \
    -e "s|__AUDIT_HMAC_KEY__|${hmac}|g" \
    -e "s|__BACKUP_DIR__|/var/backups/mosgov/${tenant}|g" \
    -e "s|__BACKUP_COS_BUCKET__|${MOSGOV_BACKUP_COS_BUCKET:-mosgov-backup-unset}|g" \
    -e "s|__BACKUP_COS_PREFIX__|${prefix}|g" \
    -e "s|__RELEASE_COMMIT__|${release_commit}|g" \
    "$envtpl" > "$envfile"
# 任何未替换的占位符都是硬失败，绝不把半成品 env 留在生产目录
if grep -qE '__[A-Z0-9_]+__' "$envfile"; then
    rm -f -- "$envfile"
    die "env 模板存在未替换的占位符，已删除半成品文件"
fi
chown root:mosgov "$envfile"
chmod 0640 "$envfile"
log "独立 env 已生成（DB 口令 / HMAC 均只写入此 0640 文件，不打印、不入 Git）"

# ---- 4. 独立 PHP-FPM pool ----------------------------------------------------
# clear_env=yes 会丢掉 Apache SetEnv；这里显式把 env 注入进程环境并关闭 clear_env。
pool="/etc/php/8.4/fpm/pool.d/mosgov-${tenant}.conf"
{
    printf '[mosgov-%s]\n' "$tenant"
    printf 'user = %s\ngroup = mosgov\n' "$sysuser"
    printf 'listen = /run/php/php8.4-fpm-mosgov-%s.sock\n' "$tenant"
    printf 'listen.owner = www-data\nlisten.group = www-data\nlisten.mode = 0660\n'
    printf 'pm = dynamic\npm.max_children = 5\npm.start_servers = 1\n'
    printf 'pm.min_spare_servers = 1\npm.max_spare_servers = 2\npm.max_requests = 500\n'
    printf 'clear_env = no\n'
    printf 'php_admin_value[open_basedir] = %s:%s:/tmp\n' "$MOSGOV_CURRENT" "$storage"
    while IFS='=' read -r k v; do
        [[ "$k" =~ ^[A-Z][A-Z0-9_]*$ ]] || continue
        printf 'env[%s] = %s\n' "$k" "$v"
    done < "$envfile"
} > "$pool"
chmod 0640 "$pool"
log "独立 PHP-FPM pool 就绪（独立 socket / 独立系统账号 / open_basedir）"

# ---- 5. 独立 Apache vhost ----------------------------------------------------
vhost_tpl="$MOSGOV_ETC/deploy/apache/mosgov-vhost.conf"
[[ -f "$vhost_tpl" ]] || vhost_tpl="$MOSGOV_SELF_DIR/../apache/mosgov-vhost.conf"
[[ -f "$vhost_tpl" ]] || die "缺少 vhost 模板"
sed -e "s|__HOST__|${host}|g" \
    -e "s|__TENANT_ID__|${tenant}|g" \
    -e "s|__TENANT_DB__|${db}|g" \
    -e "s|__TENANT_STORAGE__|${storage}|g" \
    -e "s|__DOCROOT__|${MOSGOV_CURRENT}|g" \
    "$vhost_tpl" > "/etc/apache2/sites-available/mosgov-${tenant}.conf"
a2ensite "mosgov-${tenant}" >/dev/null
apache2ctl configtest >/dev/null || die "Apache 配置校验失败"
systemctl reload php8.4-fpm || warn "php8.4-fpm reload 失败"
systemctl reload apache2 || warn "apache2 reload 失败"
log "独立 vhost 已启用"

# ---- 6. 静态存活探针（由 deploy.sh 写入发布目录，这里只校验） -------------------
probe="$MOSGOV_CURRENT/.well-known/mosgov-liveness"
[[ -f "$probe" ]] || warn "缺少静态存活探针 $probe；请确认 deploy.sh 已写入，健康检查 L1 会失败"

# ---- 7. 首个库外证据锚点 -----------------------------------------------------
MOSGOV_PLUGIN_SRC="$MOSGOV_CURRENT/$MOSGOV_PLUGIN_REL/src/Security" \
    "$PHP_BIN" "$(mosgov_php_script audit-anchor.php)" --tenant "$tenant" \
    --out "/var/backups/mosgov/${tenant}/anchors" >/dev/null \
    || warn "首个证据锚点生成失败（可稍后由 mosgov-audit-anchor.sh 补做）"

# ---- 8. 配置体检 -------------------------------------------------------------
verify_bin=""
if   [[ -x "$MOSGOV_SELF_DIR/verify-secrets.sh" ]]; then verify_bin="$MOSGOV_SELF_DIR/verify-secrets.sh"
elif [[ -x /usr/local/bin/mosgov-verify-secrets.sh ]]; then verify_bin=/usr/local/bin/mosgov-verify-secrets.sh
fi
if [[ -n "$verify_bin" ]]; then "$verify_bin" --tenant "$tenant" --quiet >/dev/null \
    || die "配置体检未通过（教会仍保持 provisioning，不对外提供服务）"; fi

# ---- 9. 标记 active ---------------------------------------------------------
jq --arg id "$tenant" '(.tenants[] | select(.tenant_id == $id) | .status) = "active"' \
    "$MOSGOV_TENANTS_JSON" > "$regtmp"
cat "$regtmp" > "$MOSGOV_TENANTS_JSON"
chmod 0640 "$MOSGOV_TENANTS_JSON"

echo
log "PROVISION_OK tenant=$tenant db=$db host=$host"
echo "下一步（需人工完成，本脚本不自动执行）："
echo "  1) 将 $host 解析到本机公网 IP，确认 80/443 已放通；"
echo "  2) sudo certbot --apache -d $host   （TLS 证书，需域名解析生效）"
echo "  3) sudo mosgov-healthcheck.sh --tenant $tenant"
echo "  4) 配置 /etc/mosgov/$tenant/cos.env（0400，子账号仅 PutObject/GetObject），再跑 mosgov-backup-db.sh --tenant $tenant --with-files"
echo "  5) 本脚本未导入任何真实教会数据。"
