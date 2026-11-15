#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 服务器初始化（Ubuntu 24.04 LTS）—— 幂等，可重复执行
# 用法: sudo ./server-init.sh
#
# 不购买资源、不改动其他项目、不创建任何教会数据库、不添加未授权的第三方 apt 源。
# 若 PHP 8.4 / MariaDB 11.8 不可用，脚本会明确退出并说明需要的授权，绝不擅自
# 添加 PPA 或升级数据库主版本。
# =============================================================================
set -euo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TPL_DIR="$SELF_DIR/.."

echo "[1/10] 基础包（固定主版本，不使用 latest / 无版本号元包）"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends \
    apache2 \
    mariadb-server mariadb-client \
    ca-certificates curl gnupg git unzip jq openssl gzip tar \
    logrotate ufw certbot python3-certbot-apache

echo "[2/10] 运行时版本闸门（Ubuntu 24.04 默认是 PHP 8.3 / MariaDB 10.11）"
# Ubuntu 24.04 官方仓库不含 PHP 8.4，也不含 MariaDB 11.8；两者都需要经授权的
# 第三方 apt 源。这里只做检查，不擅自添加，避免把未授权变更带进服务器。
if ! command -v php8.4 >/dev/null 2>&1; then
    echo "!! PHP 8.4 未安装：请按 deploy/VERSIONS.md 添加已批准的 PHP 8.4 源后重跑。"
    echo "!! 本脚本不会自动添加 ondrej/php PPA（避免未授权变更）。"
    exit 3
fi
PHP_VER="$(php8.4 -r 'echo PHP_VERSION;')"
echo "    PHP=$PHP_VER"
MB_VER="$(mariadb --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"
echo "    MariaDB=$MB_VER"
if [[ ! "$MB_VER" =~ ^11\.8\. ]]; then
    echo "!! MariaDB $MB_VER 与版本锁 11.8.9 不一致（Ubuntu 24.04 默认 10.11 LTS）。"
    echo "!! 需要单独授权添加 MariaDB 官方源，或书面批准改用 10.11 并补跑全量测试。"
    echo "!! 本脚本不会自动更换数据库主版本。"
    exit 4
fi
apt-get install -y --no-install-recommends \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-intl \
    php8.4-gd php8.4-zip php8.4-curl php8.4-xml php8.4-bcmath

echo "[3/10] 固定已安装包版本，禁止自动升级 DB / PHP 主版本"
apt-mark hold apache2 mariadb-server mariadb-client mariadb-server-core \
    php8.4-fpm php8.4-cli php8.4-mysql 2>/dev/null || true
cat > /etc/apt/apt.conf.d/51mosgov-unattended <<'EOF'
Unattended-Upgrade::Package-Blacklist {
    "mariadb-server";
    "mariadb-server-core";
    "php8.4-fpm";
    "php8.4-mysql";
};
EOF
# 版本锁清单（优先级由后续授权添加的源决定，此处只写白名单，不改源）
install -d -m 0755 /etc/apt/preferences.d
cat > /etc/apt/preferences.d/mosgov-pin <<'EOF'
# 只允许已授权的 PHP 8.4 / MariaDB 11.8 源提供下列包。
# 新增第三方源时必须同步更新 Pin: origin 与 Pin-Priority，且需单独授权。
Package: php8.4* mariadb-server mariadb-server-core mariadb-client
Pin: release o=Ubuntu
Pin-Priority: -1
EOF
dpkg-query -W -f='${binary:Package}=${Version}\n' | sort \
    > "/var/log/mosgov-package-lock-$(date +%Y%m%d).txt" 2>/dev/null \
    || install -d -m 0755 /var/log
echo "    版本快照: /var/log/mosgov-package-lock-$(date +%Y%m%d).txt"

echo "[4/10] 系统账号与目录（每教会独立子目录）"
id -u mosgov >/dev/null 2>&1 || useradd --system --shell /usr/sbin/nologin --home /var/lib/mosgov mosgov
install -d -m 0750 -o root -g mosgov /etc/mosgov
install -d -m 0750 -o root -g mosgov /etc/mosgov/deploy
install -d -m 0750 -o root -g mosgov /var/lib/mosgov
install -d -m 0750 -o root -g mosgov /var/backups/mosgov
install -d -m 0750 -o root -g mosgov /var/log/mosgov
install -d -m 0755 /var/www/mos-gov/releases
install -d -m 0755 /var/www/mos-gov/shared
install -d -m 0755 /var/www/mos-gov/mirror
install -d -m 0755 /var/www/mos-gov/staging
: > /var/www/mos-gov/RELEASE_HISTORY
chmod 0644 /var/www/mos-gov/RELEASE_HISTORY

echo "[5/10] 安装部署模板 / 锁定清单（只读，供 deploy.sh 与 provision-tenant.sh 使用）"
for sub in env config apache php; do install -d -m 0750 -o root -g mosgov "/etc/mosgov/deploy/$sub"; done
install -m 0640 -o root -g mosgov "$TPL_DIR/upstream-lock.json"     /etc/mosgov/deploy/upstream-lock.json
install -m 0640 -o root -g mosgov "$TPL_DIR/VERSIONS.md"            /etc/mosgov/deploy/VERSIONS.md
install -m 0640 -o root -g mosgov "$TPL_DIR/tenant-registry.json"   /etc/mosgov/tenants.json
install -m 0640 -o root -g mosgov "$TPL_DIR/env/mosgov.env.example" /etc/mosgov/deploy/env/mosgov.env.example
install -m 0640 -o root -g mosgov "$TPL_DIR/config/Config.php.tpl"  /etc/mosgov/deploy/config/Config.php.tpl
install -m 0640 -o root -g mosgov "$TPL_DIR/apache/mosgov-vhost.conf" /etc/mosgov/deploy/apache/mosgov-vhost.conf
install -m 0640 -o root -g mosgov "$TPL_DIR/php/99-mosgov.ini"      /etc/mosgov/deploy/php/99-mosgov.ini

echo "[6/10] Apache 模块 / PHP 配置"
a2enmod proxy_fcgi setenvif headers rewrite ssl >/dev/null
cp "$TPL_DIR/php/99-mosgov.ini" /etc/php/8.4/fpm/conf.d/99-mosgov.ini
cp "$TPL_DIR/php/99-mosgov.ini" /etc/php/8.4/cli/conf.d/99-mosgov.ini
systemctl enable --now php8.4-fpm
systemctl enable --now apache2
systemctl enable --now mariadb

echo "[7/10] MariaDB 基础加固（不导入任何数据、不创建任何教会库）"
mysql -e "DELETE FROM mysql.user WHERE User='';" || true
mysql -e "DROP DATABASE IF EXISTS test;" || true
mysql -e "FLUSH PRIVILEGES;"
# 生产库只允许本机 socket/TCP 回环访问
grep -q '^bind-address' /etc/mysql/mariadb.conf.d/50-server.cnf \
    || printf '\nbind-address = 127.0.0.1\n' >> /etc/mysql/mariadb.conf.d/50-server.cnf

echo "[8/10] 防火墙（仅 80/443/SSH；8080 只监听回环）"
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null

echo "[9/10] 日志轮转与备份 / 锚点定时任务"
cp "$TPL_DIR/logrotate/mosgov" /etc/logrotate.d/mosgov
chmod 0644 /etc/logrotate.d/mosgov
logrotate -d /etc/logrotate.d/mosgov >/dev/null
cat > /etc/cron.d/mosgov-backup <<'EOF'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
# 每天 02:15 依次备份所有 active 教会；02:35 写库外证据锚点
15 2 * * * root /usr/local/bin/mosgov-backup-all.sh >> /var/log/mosgov/backup-cron.log 2>&1
35 2 * * * root /usr/local/bin/mosgov-audit-anchor.sh >> /var/log/mosgov/anchor-cron.log 2>&1
EOF
chmod 0644 /etc/cron.d/mosgov-backup

echo "[10/10] 安装运维脚本（shell -> /usr/local/bin，PHP/库 -> /usr/local/lib/mosgov）"
install -d -m 0755 /usr/local/lib/mosgov
install -m 0644 "$SELF_DIR/mosgov-lib.sh" /usr/local/lib/mosgov/lib.sh
install -m 0644 "$SELF_DIR/mosgov-cli.php" /usr/local/lib/mosgov/cli.php
install -m 0644 "$SELF_DIR/healthcheck.php" /usr/local/lib/mosgov/healthcheck.php
install -m 0644 "$SELF_DIR/audit-anchor.php" /usr/local/lib/mosgov/audit-anchor.php

for pair in \
    "verify-secrets.sh:mosgov-verify-secrets.sh" \
    "healthcheck.sh:mosgov-healthcheck.sh" \
    "backup-db.sh:mosgov-backup-db.sh" \
    "restore-db.sh:mosgov-restore-db.sh" \
    "rollback.sh:mosgov-rollback.sh" \
    "deploy.sh:mosgov-deploy.sh" \
    "provision-tenant.sh:mosgov-provision-tenant.sh" \
    "mosgov-backup-all.sh:mosgov-backup-all.sh" \
    "mosgov-audit-anchor.sh:mosgov-audit-anchor.sh"; do
    src="${pair%%:*}"; dst="${pair#*:}"
    [[ -f "$SELF_DIR/$src" ]] || { echo "!! 缺少脚本 $src"; exit 5; }
    install -m 0755 "$SELF_DIR/$src" "/usr/local/bin/$dst"
done
chmod +x /usr/local/bin/mosgov-*.sh

echo
echo "OK: 服务器初始化完成。下一步："
echo "  1) 上传经核验的 ChurchCRM 7.8.0 源码归档，填写 /etc/mosgov/deploy/upstream-lock.json 的真实 sha256"
echo "  2) sudo mosgov-deploy.sh --release <40位commit> --dry-run"
echo "  3) sudo mosgov-deploy.sh --release <40位commit>"
echo "  4) sudo mosgov-provision-tenant.sh --tenant <id>"
echo "  5) certbot 申请证书 → sudo mosgov-healthcheck.sh --all"
echo
echo "注意：本脚本未添加任何第三方 apt 源，未购买任何资源，未创建任何教会数据库，未导入任何数据。"
