#!/usr/bin/env bash
# =============================================================================
# MOS-GOV 首个教会管理员创建（B4）—— shell 包装层
#
# 用法:
#   sudo mosgov-create-admin.sh --tenant church-a --pass-file /root/.church-a-admin.pass \
#       [--username admin] [--first-name Church] [--last-name Admin] [--email admin@example.org]
#
# 实际逻辑在 create-admin.php（PDO 参数化 + bcrypt + 事务）。本包装层只负责：
#   * 要求 root（读 0600 root-only 的 env、写 0600 的凭据文件）；
#   * 定位 PHP 与 create-admin.php；
#   * 把参数原样透传，并把 PHP 的非 0 退出码透传给调用方。
#
# 明确不做：不打印口令、不记录口令、不覆盖任何现有账户、不操作非目标租户。
# 退出码见 create-admin.php 头部（0 成功 / 2 输入非法 / 3 前置不满足 /
# 4 已存在安全退出 / 5 DB 失败已回滚 / 6 凭据文件写入失败）。
# =============================================================================
set -euo pipefail
export MOSGOV_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$MOSGOV_SELF_DIR/mosgov-lib.sh" ]]; then source "$MOSGOV_SELF_DIR/mosgov-lib.sh"
else source "${MOSGOV_LIB_DIR:-/usr/local/lib/mosgov}/lib.sh"; fi

need_root
PHP_BIN="${MOSGOV_PHP_BIN:-php8.4}"
command -v "$PHP_BIN" >/dev/null 2>&1 || PHP_BIN=php
need_cmd "$PHP_BIN"

admin_php="$(mosgov_php_script create-admin.php)"
exec "$PHP_BIN" "$admin_php" "$@"
