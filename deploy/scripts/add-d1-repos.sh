#!/usr/bin/env bash
# =============================================================================
# MOS-GOV D1：添加 PHP 8.4（Ondřej Sury）与 MariaDB 11.8 官方源
#
# 授权范围（用户已批准 D1）：
#   * 允许 ondrej/php 与 MariaDB 官方源；
#   * 必须校验官方签名指纹，指纹不符立即中止；
#   * 不启用任何收费仓库 / 订阅 / 商业组件；
#   * 保留安装版本与校验结果。
#
# 为什么必须先跑本脚本、再跑 server-init.sh：
#   server-init.sh 会 `apt-get install mariadb-server`。如果 MariaDB 源还没加，
#   apt 会装 Ubuntu 自带的 10.11，随后版本闸门 exit 4，且机器上留下一个错误
#   主版本的数据库实例（需 mariadb-upgrade 才能收拾）。
#
# 幂等：重复执行只覆盖源文件与公钥，不改动已装包。
# =============================================================================
set -euo pipefail

SURY_KEY_FPR="15058500A0235D97F5D10063B188E2B695BD4743"   # DEB.SURY.ORG Automatic Signing Key <deb@sury.org>
MARIADB_KEY_FPR="177F4010FE56CA3336300305F1656F24C74CD1D8" # MariaDB Signing Key <signing-key@mariadb.org>
SURY_KEY_URL="https://packages.sury.org/php/apt.gpg"
MARIADB_KEY_URL="https://mariadb.org/mariadb_release_signing_key.pgp"
SURY_REPO="deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ noble main"
MARIADB_REPO="deb [signed-by=/usr/share/keyrings/mariadb-11.8.gpg] https://dlm.mariadb.com/repo/mariadb-server/11.8/repo/ubuntu noble main"

die() { echo "!! $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || die "需要 root 执行"
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || die "仅支持 Ubuntu（当前 ${ID:-unknown}）"
[ "${VERSION_CODENAME:-}" = "noble" ] || die "仅支持 Ubuntu 24.04 noble（当前 ${VERSION_CODENAME:-unknown}）"
arch="$(dpkg --print-architecture)"
case "$arch" in arm64|amd64) : ;; *) die "不支持的架构 $arch" ;; esac
echo "平台：Ubuntu ${VERSION_CODENAME} / ${arch}"

command -v gpg >/dev/null 2>&1 || {
    echo "[0/5] 安装 gnupg（用于校验签名指纹）"
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends gnupg ca-certificates >/dev/null
}
install -d -m 0755 /usr/share/keyrings

fetch_verify_key() { # fetch_verify_key <url> <expected_fpr> <dest>
    local url="$1" want="$2" dest="$3" tmp fpr
    tmp="$(mktemp)"
    curl -fsSL --max-time 60 "$url" -o "$tmp" || die "下载公钥失败：$url"
    fpr="$(gpg --show-keys --with-colons "$tmp" 2>/dev/null | awk -F: '/^fpr:/{print $10; exit}')"
    [ "$fpr" = "$want" ] || die "公钥指纹不符！期望 $want，实际 ${fpr:-空}（来源 $url）"
    install -m 0644 "$tmp" "$dest"
    echo "    指纹一致：$fpr  → $dest"
    rm -f "$tmp"
}

echo "[1/5] 校验并安装 Ondřej Sury PHP 源公钥"
fetch_verify_key "$SURY_KEY_URL" "$SURY_KEY_FPR" /usr/share/keyrings/sury-php.gpg
printf '%s\n' "$SURY_REPO" > /etc/apt/sources.list.d/sury-php.list

echo "[2/5] 校验并安装 MariaDB 官方源公钥"
fetch_verify_key "$MARIADB_KEY_URL" "$MARIADB_KEY_FPR" /usr/share/keyrings/mariadb-11.8.gpg
printf '%s\n' "$MARIADB_REPO" > /etc/apt/sources.list.d/mariadb-11.8.list

echo "[3/5] 只更新这两个源（不触碰其他源）"
apt-get update \
    -o Dir::Etc::sourcelist=/etc/apt/sources.list.d/sury-php.list \
    -o Dir::Etc::sourceparts=/dev/null -o APT::Get::List-Cleanup=0 >/dev/null
apt-get update \
    -o Dir::Etc::sourcelist=/etc/apt/sources.list.d/mariadb-11.8.list \
    -o Dir::Etc::sourceparts=/dev/null -o APT::Get::List-Cleanup=0 >/dev/null

echo "[4/5] 候选版本核对（必须来自已授权源）"
mb_cand="$(apt-cache policy mariadb-server | awk '/Candidate:/{print $2}')"
php_cand="$(apt-cache policy php8.4-fpm   | awk '/Candidate:/{print $2}')"
echo "    mariadb-server candidate = ${mb_cand:-<none>}"
echo "    php8.4-fpm     candidate = ${php_cand:-<none>}"
case "$mb_cand" in *maria~ubu2404*) : ;; *) die "mariadb-server 候选版本不是 MariaDB 官方源构建（${mb_cand:-空}）" ;; esac
[ -n "$php_cand" ] || die "php8.4-fpm 在源中不存在"

echo "[5/5] 锁定：这些包只从授权源取（Ubuntu 自带版本优先级设为 -1）"
cat > /etc/apt/preferences.d/mosgov-pin <<'EOF'
# D1 授权范围：php8.4* 与 mariadb-* 只允许来自已校验签名的授权源。
# 任何新增第三方源都必须先更新本文件并取得单独授权。
Package: php8.4* mariadb-server mariadb-server-core mariadb-client
Pin: release o=Ubuntu
Pin-Priority: -1
EOF

echo
echo "完成。下一步：sudo bash deploy/scripts/server-init.sh"
echo "预期：PHP 8.4.x、MariaDB 11.8.x 均由授权源提供，server-init.sh 的版本闸门应通过。"
