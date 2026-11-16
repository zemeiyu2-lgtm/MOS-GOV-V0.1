#!/usr/bin/env bash
# =============================================================================
# MOS-GOV Phase 8 —— Linux 生产部署链 + 恢复能力实测（隔离环境，合成数据）
#
# 用法（root，Ubuntu 24.04）：
#   sudo RELEASE_SHA=<40-hex> ARTIFACT_DIR=<目录> bash tests/phase8/linux-e2e.sh
#
# 覆盖 Phase B：
#   B1 正式脚本安装 ChurchCRM 7.8.0（锁定归档 + 官方补丁 + 插件）
#   B2 创建独立教会实例（church-a / church-b）
#   B3 核心库初始化顺序（核心 schema → version_ver → 001..006 迁移）
#   B4 MOS-GOV 插件启用状态（config_cfg plugin.mos-gov.enabled）
#   B5 mosgov-create-admin + seed Admin 停用
#   B6 正式管理员首次登录 + 强制改密（真实 HTTP）
#   B7 普通用户无法进入管理员功能
#   B8 两教会数据 / 文件 / 会话隔离
# 覆盖 Phase C：
#   C1 Linux 全项 healthcheck（L1 HTTP + L2 运行时 + L3 基础设施）
#   C2 数据库 + 文件完整备份
#   C3 恢复前后表行数 / 文件数量与哈希比对
#   C4 两教会分别恢复且互不影响
#   C5 恢复后最小权限仍可用（应用账号可写业务表）
#   C6 备份 / env / 存储权限与敏感配置检查
#   C7 恢复不得重新启用默认口令的 seed Admin
#
# 说明：不对真实教会数据做任何操作；所有租户主机名为 *.mosgov.internal
#       （RFC 8375 保留的私用 TLD），仅在本机 Host 头中使用。
# =============================================================================
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ART="${ARTIFACT_DIR:-$REPO/evidence/phase8}"
WORK="${P8_WORK:-/var/tmp/mosgov-p8}"
mkdir -p "$ART" "$WORK"
LOG="$ART/20-linux-e2e.log"
: > "$LOG"
exec > >(tee -a "$LOG") 2>&1

export P8_WORK="$WORK"
# shellcheck source=/dev/null
source "$REPO/tests/phase8/lib.sh"

RELEASE_SHA="${RELEASE_SHA:-}"
HOST_A="a.mosgov.internal"
HOST_B="b.mosgov.internal"
TENANT_A="church-a"
TENANT_B="church-b"
DB_A="mosgov_church_a"
DB_B="mosgov_church_b"
STORAGE_A="/var/lib/mosgov/church-a/files"
STORAGE_B="/var/lib/mosgov/church-b/files"
TMP_A="/var/lib/mosgov/church-a/tmp"
TMP_B="/var/lib/mosgov/church-b/tmp"
SESS_A="$TMP_A/sessions"
SESS_B="$TMP_B/sessions"
LOGDIR_A="/var/log/mosgov/php/church-a"
LOGDIR_B="/var/log/mosgov/php/church-b"
USER_A="mosgov-church-a"
USER_B="mosgov-church-b"
PASSFILE_A="/root/.p8-church-a-admin.pass"
STAGE_START=0

stage() { p8_hdr "$*"; STAGE_START="$(date +%s)"; }
stage_end() { printf '  [TIME] %s 秒\n' "$(( $(date +%s) - STAGE_START ))"; }

# OS 层隔离探针：owner 读写删替换全部可用；guest 读/写/创建/删/替换全部被拒。
# 用真实 Linux 系统用户（sudo -u）执行，不依赖 open_basedir 或应用层判断。
p8_private_dir() {
    local label="$1" dir="$2" owner="$3" guest="$4"
    p8_eq "$label 权限 0700" "0700" "$(stat -c '%04a' "$dir" 2>/dev/null)"
    p8_eq "$label 属主/独立组 $owner" "$owner $owner" "$(stat -c '%U %G' "$dir" 2>/dev/null)"
    if sudo -u "$owner" bash -c "printf 'owner\n' > '$dir/p8-owner.txt'" 2>/dev/null; then
        p8_ok "$label 属主可写"
    else
        p8_bad "$label 属主不可写（自身业务会中断）"
    fi
    if sudo -u "$owner" cat "$dir/p8-owner.txt" >/dev/null 2>&1; then p8_ok "$label 属主可读"; else p8_bad "$label 属主不可读"; fi
    if sudo -u "$owner" bash -c "printf 'new\n' > '$dir/p8-owner.txt'" 2>/dev/null; then p8_ok "$label 属主可覆写"; else p8_bad "$label 属主不可覆写"; fi
    if sudo -u "$owner" bash -c "mv '$dir/p8-owner.txt' '$dir/p8-owner2.txt'" 2>/dev/null; then p8_ok "$label 属主可改名"; else p8_bad "$label 属主不可改名"; fi
    if sudo -u "$owner" rm -f "$dir/p8-owner2.txt" 2>/dev/null; then p8_ok "$label 属主可删除"; else p8_bad "$label 属主不可删除"; fi

    sudo -u "$owner" bash -c "printf 'secret\n' > '$dir/p8-secret.txt'" 2>/dev/null
    if sudo -u "$guest" cat "$dir/p8-secret.txt" >/dev/null 2>&1; then p8_bad "$guest 可读 $label"; else p8_ok "$guest 不可读 $label"; fi
    if sudo -u "$guest" bash -c "printf 'x' > '$dir/p8-secret.txt'" 2>/dev/null; then p8_bad "$guest 可覆写 $label"; else p8_ok "$guest 不可覆写 $label"; fi
    if sudo -u "$guest" bash -c "printf 'x' > '$dir/p8-guest.txt'" 2>/dev/null; then p8_bad "$guest 可在 $label 创建"; else p8_ok "$guest 不可在 $label 创建"; fi
    if sudo -u "$guest" bash -c "rm -f '$dir/p8-secret.txt'" 2>/dev/null; then p8_bad "$guest 可删除 $label 文件"; else p8_ok "$guest 不可删除 $label 文件"; fi
    if sudo -u "$guest" bash -c "mv '$dir/p8-secret.txt' '$dir/p8-guest.txt'" 2>/dev/null; then p8_bad "$guest 可替换 $label 文件"; else p8_ok "$guest 不可替换 $label 文件"; fi
    sudo -u "$owner" rm -f "$dir/p8-secret.txt" 2>/dev/null
}

# 恢复后轻量复核：权限/属主/独立组 + guest 读写拒绝。
p8_private_dir_after_restore() {
    local label="$1" dir="$2" owner="$3" guest="$4"
    p8_eq "$label 恢复后权限 0700" "0700" "$(stat -c '%04a' "$dir" 2>/dev/null)"
    p8_eq "$label 恢复后属主/独立组" "$owner $owner" "$(stat -c '%U %G' "$dir" 2>/dev/null)"
    if sudo -u "$guest" test -r "$dir" 2>/dev/null; then p8_bad "$guest 恢复后仍可读 $label"; else p8_ok "$guest 恢复后不可读 $label"; fi
    if sudo -u "$guest" bash -c "printf x > '$dir/p8-guest-after.txt'" 2>/dev/null; then p8_bad "$guest 恢复后仍可写 $label"; else p8_ok "$guest 恢复后不可写 $label"; fi
}

# =============================================================================
stage "S0 环境前置"
# =============================================================================
[[ "$(id -u)" -eq 0 ]] && p8_ok "以 root 运行" || { p8_bad "必须以 root 运行"; exit 1; }
# shellcheck disable=SC1091
. /etc/os-release
p8_eq "操作系统为 Ubuntu 24.04" "24.04" "${VERSION_ID:-}"
p8_eq "codename noble" "noble" "${VERSION_CODENAME:-}"
p8_info "arch=$(dpkg --print-architecture) nproc=$(nproc) mem=$(free -m | awk '/^Mem:/{print $2}')MB"
p8_ge "根分区可用空间 ≥ 8 GiB" 8388608 "$(df -Pk / | awk 'NR==2{print $4}')"

# GitHub 托管镜像自带 MySQL；先停掉，避免它占用 3306 与 MariaDB 冲突。
for svc in mysql mysqld; do
    systemctl stop "$svc" >/dev/null 2>&1 || true
    systemctl disable "$svc" >/dev/null 2>&1 || true
done
pkill -f '/usr/sbin/mysqld' >/dev/null 2>&1 || true
for _ in $(seq 1 20); do
    ss -ltn 2>/dev/null | grep -q ':3306 ' || break
    sleep 0.5
done
p8_info "3306 监听：$(ss -ltn 2>/dev/null | awk '/:3306 /{print $4}' | tr '\n' ' ')"

# ufw 需要 OpenSSH 应用档案才允许 `ufw allow OpenSSH`
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    openssh-server unzip >/dev/null 2>&1 || true
p8_ok "基础工具就绪（openssh-server / unzip）"
stage_end

# =============================================================================
stage "S1 D1 授权软件源（签名指纹校验）"
# =============================================================================
if bash "$REPO/deploy/scripts/add-d1-repos.sh" > "$ART/21-add-d1-repos.log" 2>&1; then
    p8_ok "add-d1-repos.sh exit=0"
else
    p8_bad "add-d1-repos.sh 失败，详见 21-add-d1-repos.log"
    tail -20 "$ART/21-add-d1-repos.log"
    p8_abort
fi
grep -E '^(平台|    指纹一致)' "$ART/21-add-d1-repos.log" | head -4
stage_end

# =============================================================================
stage "S2 server-init.sh（隧道模式，全量安装）"
# =============================================================================
INIT_RC=0
MOSGOV_INGRESS_MODE=tunnel bash "$REPO/deploy/scripts/server-init.sh" \
    > "$ART/22-server-init.log" 2>&1 || INIT_RC=$?
p8_exit "server-init.sh" 0 "$INIT_RC"
grep -E '^(    PHP=|    MariaDB=|\[4/10\]|\[10/10\])' "$ART/22-server-init.log" | head -6
if [[ "$INIT_RC" -ne 0 ]]; then
    p8_warn "server-init 失败，输出末尾："
    tail -30 "$ART/22-server-init.log"
    p8_abort
fi
stage_end

# =============================================================================
stage "S3 运行时版本闸门与端口绑定"
# =============================================================================
PHPV="$(php8.4 -r 'echo PHP_VERSION;' 2>/dev/null || echo none)"
p8_has "PHP 8.4 可用" "8.4." "$PHPV"
MBV="$(mariadb --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"
p8_has "MariaDB 11.8.x" "11.8." "${MBV:-none}"
p8_has "Apache 2.4" "Apache/2.4" "$(apache2 -v 2>/dev/null | head -1)"
p8_has "proxy_fcgi 已加载" "proxy_fcgi_module" "$(apache2ctl -M 2>/dev/null)"
SS="$(ss -ltn 2>/dev/null)"
p8_has "8080 监听在回环" "127.0.0.1:8080" "$SS"
[[ "$SS" == *"0.0.0.0:8080"* ]] && p8_bad "8080 监听 0.0.0.0（不得对外暴露）" || p8_ok "8080 未监听 0.0.0.0"
p8_eq "ingress-mode 为 tunnel" "tunnel" "$(cat /etc/mosgov/ingress-mode 2>/dev/null)"
p8_info "版本快照文件：$(ls /var/log/mosgov*package* /var/log/mosgov/package* 2>/dev/null | tr '\n' ' ')"
stage_end

# =============================================================================
stage "S4 锁定归档准备与完整性校验"
# =============================================================================
LOCK="/etc/mosgov/deploy/upstream-lock.json"
[[ -f "$LOCK" ]] || LOCK="$REPO/deploy/upstream-lock.json"
URL="$(jq -r '.churchcrm.archive_url' "$LOCK")"
WANT="$(jq -r '.churchcrm.sha256' "$LOCK")"
ARCHIVE="$(jq -r '.churchcrm.archive_path' "$LOCK")"
p8_info "归档 URL：$URL"
p8_info "期望 sha256：${WANT:0:16}…"
install -d -m 0755 "$(dirname "$ARCHIVE")"
if curl -sSL --max-time 600 -o "$ARCHIVE" "$URL"; then
    p8_ok "归档下载完成 ($(stat -c '%s' "$ARCHIVE") 字节)"
else
    p8_bad "归档下载失败"
fi
GOT="$(sha256sum "$ARCHIVE" 2>/dev/null | awk '{print $1}')"
p8_eq "归档 sha256 与锁定值一致" "$WANT" "$GOT"
stage_end

# =============================================================================
stage "S5 正式发布 deploy.sh --release"
# =============================================================================
if [[ ! "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]; then
    p8_bad "RELEASE_SHA 非法：$RELEASE_SHA"
    RELEASE_SHA="$(git -C "$REPO" rev-parse HEAD 2>/dev/null || true)"
    p8_info "回退为本地 HEAD：$RELEASE_SHA"
fi
DEP_RC=0
mosgov-deploy.sh --release "$RELEASE_SHA" > "$ART/23-deploy.log" 2>&1 || DEP_RC=$?
p8_exit "mosgov-deploy.sh" 0 "$DEP_RC"
grep -E '^\[mosgov\]' "$ART/23-deploy.log" | tail -12
if [[ "$DEP_RC" -ne 0 ]]; then
    p8_warn "发布失败，输出末尾："
    tail -25 "$ART/23-deploy.log"
fi
p8_eq "REVISION 与发布 commit 一致" "$RELEASE_SHA" "$(cat /var/www/mos-gov/current/REVISION 2>/dev/null)"
p8_eq "插件版本 2.0.0" "2.0.0" "$(jq -r '.version' /var/www/mos-gov/current/plugins/community/mos-gov/plugin.json 2>/dev/null)"
p8_file "静态存活探针已写入" "/var/www/mos-gov/current/.well-known/mosgov-liveness"
p8_file "上游补丁已应用到 GroupService.php" "/var/www/mos-gov/current/ChurchCRM/Service/GroupService.php"
grep -q 'guardedId' /var/www/mos-gov/current/ChurchCRM/Service/GroupService.php 2>/dev/null \
    && p8_ok "GroupService 补丁产物含 guardedId" || p8_bad "GroupService 未见补丁产物"
stage_end

# =============================================================================
stage "S6 独立教会实例开通（church-a / church-b）"
# =============================================================================
# 仓库内登记表是示例主机（*.example.org），provision-tenant.sh 会主动拒绝
# 示例/保留域名。实验室改用 RFC 8375 私用 TLD 的合成主机名，仍走同一脚本、
# 同一隔离路径；不触碰任何真实域名。
if [[ -f /etc/mosgov/tenants.json ]]; then
    jq --arg a "$HOST_A" --arg b "$HOST_B" \
        '(.tenants[] | select(.tenant_id == "church-a") | .host) = $a
       | (.tenants[] | select(.tenant_id == "church-b") | .host) = $b' \
        /etc/mosgov/tenants.json > "$WORK/tenants.json"
    install -m 0640 -o root -g mosgov "$WORK/tenants.json" /etc/mosgov/tenants.json
    p8_ok "实验室租户主机名已设为 $HOST_A / $HOST_B"
fi

for T in "$TENANT_A" "$TENANT_B"; do
    RC=0
    mosgov-provision-tenant.sh --tenant "$T" > "$ART/24-provision-${T}.log" 2>&1 || RC=$?
    p8_exit "provision $T" 0 "$RC"
    if [[ "$RC" -ne 0 ]]; then tail -15 "$ART/24-provision-${T}.log"; fi
done

p8_eq "两个教会数据库各自存在" "2" \
    "$(sqlq "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ('$DB_A','$DB_B')")"
p8_eq "church-a 核心表 person_per 存在" "1" \
    "$(sqlq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_A' AND TABLE_NAME='person_per'")"
p8_eq "version_ver 已登记 7.8.0" "7.8.0" "$(sqlq "SELECT ver_version FROM $DB_A.version_ver ORDER BY ver_update_end DESC LIMIT 1")"
p8_eq "MOS-GOV 插件默认启用" "1" \
    "$(sqlq "SELECT cfg_value FROM $DB_A.config_cfg WHERE cfg_name='plugin.mos-gov.enabled'")"
p8_eq "gov_tenant 锚点 tenant_id" "$TENANT_A" "$(sqlq "SELECT tenant_id FROM $DB_A.gov_tenant WHERE singleton=1")"
p8_eq "gov_tenant 锚点(B) tenant_db" "$DB_B" "$(sqlq "SELECT tenant_db FROM $DB_B.gov_tenant WHERE singleton=1")"
p8_eq "A 与 B 的 tenant_id 不串库" "0" \
    "$(sqlq "SELECT COUNT(*) FROM $DB_B.gov_tenant WHERE tenant_id='$TENANT_A'")"
p8_eq "gov_evidence_log 触发器 3 个(A)" "3" \
    "$(sqlq "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DB_A' AND EVENT_OBJECT_TABLE='gov_evidence_log'")"
# ChurchCRM 在 sChurchName 为空时会把所有页面重定向到首次引导，
# 后续真实 HTTP 断言会全部拿不到 200。实验室先写入合成教会名。
for D in "$DB_A" "$DB_B"; do
    sqlr "INSERT INTO $D.config_cfg (cfg_name,cfg_value) VALUES ('sChurchName','MOS-GOV Lab (synthetic)')
          ON DUPLICATE KEY UPDATE cfg_value=VALUES(cfg_value)" >/dev/null
done
p8_eq "合成教会名已写入 A" "MOS-GOV Lab (synthetic)" \
    "$(sqlq "SELECT cfg_value FROM $DB_A.config_cfg WHERE cfg_name='sChurchName'")"

for T in "$TENANT_A" "$TENANT_B"; do
    p8_eq "$T env 权限 0600 root:root" "0600 root:root" \
        "$(stat -c '%04a %U:%G' "/etc/mosgov/$T/env" 2>/dev/null)"
    [[ -S "/run/php/php8.4-fpm-mosgov-$T.sock" ]] \
        && p8_ok "$T 独立 FPM socket 就绪" || p8_bad "$T 缺少 FPM socket"
    [[ -L "/etc/apache2/sites-enabled/mosgov-$T.conf" ]] \
        && p8_ok "$T vhost 已启用" || p8_bad "$T vhost 未启用"
done
stage_end

# =============================================================================
stage "S7 首个管理员创建 + 首次登录 + 强制改密"
# =============================================================================
rm -f "$PASSFILE_A"
CA_RC=0
CA_OUT="$(mosgov-create-admin.sh --tenant "$TENANT_A" --pass-file "$PASSFILE_A" \
    --username office --first-name Church --last-name Steward \
    --email office@lab.invalid 2>"$WORK/create-admin.err")" || CA_RC=$?
p8_exit "mosgov-create-admin church-a" 0 "$CA_RC"
[[ "$CA_RC" -ne 0 ]] && { cat "$WORK/create-admin.err"; }
p8_eq "create-admin 报告 seed_admin_disabled=1" "true" \
    "$(printf '%s' "$CA_OUT" | jq -r 'if (.seed_admin_disabled == true or .seed_admin_disabled == 1) then "true" else (.seed_admin_disabled // "" | tostring) end')"
p8_eq "seed per_ID=1 已停用（per_DateDeactivated 非空）" "1" \
    "$(sqlq "SELECT COUNT(*) FROM $DB_A.person_per WHERE per_ID=1 AND per_DateDeactivated IS NOT NULL")"
p8_eq "正式管理员 usr_Admin=1" "1" \
    "$(sqlq "SELECT usr_Admin FROM $DB_A.user_usr WHERE usr_UserName='office'")"
p8_eq "正式管理员首次登录需改密" "1" \
    "$(sqlq "SELECT usr_NeedPasswordChange FROM $DB_A.user_usr WHERE usr_UserName='office'")"
p8_eq "凭据文件权限 0600" "600" "$(stat -c '%a' "$PASSFILE_A" 2>/dev/null)"

export P8_HOST="$HOST_A"
ck_reset
p8_eq "登录页可达" "200" "$(p8_req GET /session/begin)"

ADMINPW="$(tr -d '\r\n' < "$PASSFILE_A")"
CODE="$(p8_req POST /session/begin --data-urlencode "User=office" --data-urlencode "Password=$ADMINPW")"
p8_eq "管理员登录返回 302" "302" "$CODE"
p8_has "登录后跳转 dashboard" "/v2/dashboard" "$(p8_loc)"

CODE="$(p8_req GET /v2/dashboard)"
p8_eq "首次进入 dashboard 被重定向" "302" "$CODE"
p8_has "强制改密页" "changepassword" "$(p8_loc)"

p8_req GET "$(p8_loc_path)" >/dev/null
TOK="$(grep -oE 'name="csrf_token" value="[^"]+"' "$P8_BODY" | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')"
[[ -n "$TOK" ]] && p8_ok "取得改密表单 CSRF 令牌" || p8_bad "未取得 CSRF 令牌"
NEWPW="P8-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')-Zq"
CODE="$(p8_req POST /v2/user/current/changepassword \
    --data-urlencode "csrf_token=$TOK" --data-urlencode "OldPassword=$ADMINPW" \
    --data-urlencode "NewPassword1=$NEWPW" --data-urlencode "NewPassword2=$NEWPW")"
p8_eq "改密提交返回 302" "302" "$CODE"
p8_eq "改密后进入仪表盘 200" "200" "$(p8_req GET /v2/dashboard)"
p8_eq "管理员可进入 /admin/ 管理界面" "200" "$(p8_req GET /admin/)"
unset ADMINPW

# 合成普通用户：验证非管理员拿不到管理功能
MEMBERPW="$(php8.4 -r 'echo bin2hex(random_bytes(12));')"
MHASH="$(php8.4 -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$MEMBERPW")"
sqlr "INSERT INTO $DB_A.person_per (per_FirstName,per_LastName,per_Email,per_DateEntered,per_EnteredBy)
      VALUES ('Lab','Member','member@lab.invalid',NOW(),1)" >/dev/null
MPID="$(sqlq "SELECT MAX(per_ID) FROM $DB_A.person_per")"
sqlr "INSERT INTO $DB_A.user_usr (usr_per_ID,usr_UserName,usr_Password,usr_NeedPasswordChange,
        usr_Admin,usr_AddRecords,usr_EditRecords,usr_DeleteRecords,usr_MenuOptions,
        usr_ManageGroups,usr_Finance,usr_Notes,usr_LastLogin,usr_LoginCount,usr_FailedLogins,usr_EditSelf)
      VALUES ($MPID,'labmember','$MHASH',0,0,0,0,0,0,0,0,0,NOW(),0,0,0)" >/dev/null
unset MHASH
p8_eq "合成普通用户已创建" "1" "$(sqlq "SELECT COUNT(*) FROM $DB_A.user_usr WHERE usr_UserName='labmember'")"

ck_reset
p8_eq "普通用户登录返回 302" "302" \
    "$(p8_req POST /session/begin --data-urlencode "User=labmember" --data-urlencode "Password=$MEMBERPW")"
unset MEMBERPW
ADM_CODE="$(p8_req GET /admin/)"
case "$ADM_CODE" in
    403|302) p8_ok "普通用户访问 /admin/ 被拒（$ADM_CODE）" ;;
    *) p8_bad "普通用户访问 /admin/ 返回 $ADM_CODE（期望 403/302）" ;;
esac
p8_ne "普通用户会话不是管理员" "200" "$ADM_CODE"
stage_end

# =============================================================================
stage "S8 两教会隔离（数据库 / 存储 / 会话）"
# =============================================================================
p8_eq "A 库没有 B 的租户锚点" "0" "$(sqlq "SELECT COUNT(*) FROM $DB_A.gov_tenant WHERE tenant_id<>'$TENANT_A'")"
p8_eq "B 库没有 A 的管理员账户" "0" "$(sqlq "SELECT COUNT(*) FROM $DB_B.user_usr WHERE usr_UserName='office'")"
p8_eq "两库都完整初始化（表数量一致）" \
    "$(sqlq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_A'")" \
    "$(sqlq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_B'")"

# OS 层双向隔离：存储 / PHP 临时 / 上传（同 tmp）/ 会话 / 日志四目录，
# A、B 真实系统用户各自可正常用，guest 对对方目录读写删替换全部被拒。
for spec in \
    "A 私有存储|$STORAGE_A|$USER_A|$USER_B" \
    "B 私有存储|$STORAGE_B|$USER_B|$USER_A" \
    "A PHP 临时目录|$TMP_A|$USER_A|$USER_B" \
    "B PHP 临时目录|$TMP_B|$USER_B|$USER_A" \
    "A PHP 会话目录|$SESS_A|$USER_A|$USER_B" \
    "B PHP 会话目录|$SESS_B|$USER_B|$USER_A" \
    "A PHP 日志目录|$LOGDIR_A|$USER_A|$USER_B" \
    "B PHP 日志目录|$LOGDIR_B|$USER_B|$USER_A"; do
    IFS='|' read -r label dir owner guest <<< "$spec"
    p8_private_dir "$label" "$dir" "$owner" "$guest"
done
# env 含 DB 口令 / HMAC，不能让同组其他教会系统账号读取
p8_eq "A env 0600 root:root" "0600 root:root" "$(stat -c '%04a %U:%G' "/etc/mosgov/$TENANT_A/env" 2>/dev/null)"
p8_eq "B env 0600 root:root" "0600 root:root" "$(stat -c '%04a %U:%G' "/etc/mosgov/$TENANT_B/env" 2>/dev/null)"
for T in "$TENANT_A" "$TENANT_B"; do
    p8_eq "$T 配置目录 0700 root:root" "0700 root:root" "$(stat -c '%04a %U:%G' "/etc/mosgov/$T" 2>/dev/null)"
    pool="/etc/php/8.4/fpm/pool.d/mosgov-$T.conf"
    p8_eq "$T pool 不再放行共享 /tmp" "0" \
        "$(grep -c 'open_basedir.*:/tmp' "$pool" 2>/dev/null || true)"
    p8_eq "$T pool 设置独立 session.save_path" "1" \
        "$(grep -c "session.save_path.*/var/lib/mosgov/$T/tmp/sessions" "$pool" 2>/dev/null || true)"
    p8_eq "$T pool 设置独立 sys_temp_dir" "1" \
        "$(grep -c "sys_temp_dir.*/var/lib/mosgov/$T/tmp" "$pool" 2>/dev/null || true)"
done
# 保留一份合成文件作为后续备份/恢复的真实文件样本（Phase C 必须验证文件内容）。
sudo -u "$USER_A" bash -c "printf 'p8-isolation-a\n' > '$STORAGE_A/p8-probe-a.txt'" 2>/dev/null
sudo -u "$USER_B" bash -c "printf 'p8-isolation-b\n' > '$STORAGE_B/p8-probe-b.txt'" 2>/dev/null
p8_file "A 私有存储合成样本存在" "$STORAGE_A/p8-probe-a.txt"
p8_file "B 私有存储合成样本存在" "$STORAGE_B/p8-probe-b.txt"
p8_eq "A/B 隔离存储根不同" "1" \
    "$([[ "$STORAGE_A" != "$STORAGE_B" ]] && echo 1 || echo 0)"
p8_eq "A/B 数据库账号不同" "1" \
    "$([[ "$(sqlq "SELECT COUNT(DISTINCT User) FROM mysql.user WHERE User IN ('$DB_A','$DB_B')")" == "2" ]] && echo 1 || echo 0)"

# 会话隔离：清空 Cookie 后用 B 的 Host 访问，未登录不得直接进入
export P8_HOST="$HOST_B"
ck_reset
BCODE="$(p8_req GET /v2/dashboard)"
case "$BCODE" in
    302) p8_ok "B 站点未登录访问被重定向（302）" ;;
    200) p8_bad "B 站点未登录直接 200（会话/租户边界失效）" ;;
    *)   p8_bad "B 站点未登录访问返回 $BCODE（body 首行：$(head -c 300 "$P8_BODY" | head -1 | tr -d '\0' | cut -c1-200)）"
         tail -20 "/var/log/mosgov/php/${TENANT_B}/php.log" 2>/dev/null || true ;;
esac
export P8_HOST="$HOST_A"
stage_end

# =============================================================================
stage "S9 Linux 全项 healthcheck（L1+L2+L3，真实 HTTP）"
# =============================================================================
HC_RC=0
mosgov-healthcheck.sh --all > "$ART/25-healthcheck.log" 2>&1 || HC_RC=$?
p8_exit "mosgov-healthcheck.sh --all" 0 "$HC_RC"
grep -E '^(PASS|FAIL)' "$ART/25-healthcheck.log" || true
HC_FAIL="$(grep -c '^FAIL' "$ART/25-healthcheck.log")"
p8_eq "healthcheck 失败项为 0" "0" "$HC_FAIL"
p8_has "L1 存活探针通过" "L1 存活探针 200" "$(cat "$ART/25-healthcheck.log")"
p8_has "L2 运行时通过" "L2 运行时全部通过" "$(cat "$ART/25-healthcheck.log")"
stage_end

# =============================================================================
# =============================================================================
# 从备份的 digest 文件取出对应的文件归档路径（digest 第 2 行是 files-*.tar.gz）
bk_files_for() {
    local f="$1" dig="${1%.sql.gz}.sha256" p=""
    [[ -f "$dig" ]] && p="$(awk 'NR==2{print $2}' "$dig")"
    [[ -n "$p" && -f "$p" ]] && printf '%s' "$p"
}

stage "S10 数据库 + 文件完整备份（两教会）"
# =============================================================================
db_snapshot "$DB_A" "$WORK/db_a_before.txt"
db_snapshot "$DB_B" "$WORK/db_b_before.txt"
NFA="$(file_snapshot "$STORAGE_A" "$WORK/files_a_before.txt")"
NFB="$(file_snapshot "$STORAGE_B" "$WORK/files_b_before.txt")"
p8_ge "保存前 A 表数量" 20 "$(wc -l < "$WORK/db_a_before.txt")"
p8_ge "保存前 A 文件数" 1 "${NFA:-0}"
p8_ge "保存前 B 文件数" 1 "${NFB:-0}"
# seed Admin 用户行指纹：恢复前后必须一致（证明恢复没有改写/重置该账户）
SEEDROW_A="$(sqlq "SELECT SHA2(CONCAT_WS('|',usr_UserName,usr_Password,usr_Admin,usr_NeedPasswordChange),256)
                   FROM $DB_A.user_usr WHERE usr_per_ID=1")"
p8_eq "seed 用户行指纹已记录" "64" "$(printf '%s' "$SEEDROW_A" | wc -c)"

BK_A=""; BK_B=""
for T in "$TENANT_A" "$TENANT_B"; do
    RC=0
    OUT="$(mosgov-backup-db.sh --tenant "$T" --with-files 2>&1)" || RC=$?
    printf '%s\n' "$OUT" > "$ART/26-backup-${T}.log"
    p8_exit "backup $T --with-files" 0 "$RC"
    F="$(printf '%s' "$OUT" | sed -n 's/^BACKUP_OK .*file=\([^ ]*\).*/\1/p' | tail -1)"
    [[ -n "$F" ]] && p8_ok "backup $T 产出 $(basename "$F")" || p8_bad "backup $T 未返回文件路径"
    if [[ "$T" == "$TENANT_A" ]]; then BK_A="$F"; else BK_B="$F"; fi
    p8_eq "备份文件权限 0400 ($T)" "400" "$(stat -c '%a' "$F" 2>/dev/null)"
    p8_eq "manifest 存在且 schema_version=1 ($T)" "1" \
        "$(jq -r '.schema_version' "${F%.sql.gz}.manifest.json" 2>/dev/null)"
    p8_eq "manifest 记录证据链已核验 ($T)" "true" \
        "$(jq -r '.evidence_chain.verified' "${F%.sql.gz}.manifest.json" 2>/dev/null)"
done
p8_ge "备份清单记录 A 的文件数" 1 \
    "$(jq -r '.files_archive.entries' "${BK_A%.sql.gz}.manifest.json" 2>/dev/null)"
p8_eq "备份清单记录 A 的文件树哈希（64 hex）" "64" \
    "$(jq -r '.files_archive.tree_sha256' "${BK_A%.sql.gz}.manifest.json" 2>/dev/null | tr -d '\n' | wc -c)"
FILES_A="$(bk_files_for "$BK_A")"
p8_file "A 文件归档存在" "$FILES_A"
FILES_B="$(bk_files_for "$BK_B")"
p8_file "B 文件归档存在" "$FILES_B"
stage_end

# =============================================================================
stage "S11 church-a 恢复（篡改后回滚到备份点）"
# =============================================================================
# 先篡改：写入标记行 + 新增文件，证明恢复确实覆盖了当前状态
sqlr "INSERT INTO $DB_A.config_cfg (cfg_name,cfg_value) VALUES ('p8_restore_marker','dirty')
      ON DUPLICATE KEY UPDATE cfg_value='dirty'" >/dev/null
printf 'dirty\n' > "$STORAGE_A/p8-dirty.txt"
p8_eq "篡改标记已写入" "dirty" "$(sqlq "SELECT cfg_value FROM $DB_A.config_cfg WHERE cfg_name='p8_restore_marker'")"

RC=0
mosgov-restore-db.sh --tenant "$TENANT_A" --file "$BK_A" --yes --confirm RESTORE \
    > "$ART/27-restore-a.log" 2>&1 || RC=$?
p8_exit "restore church-a" 0 "$RC"
grep -E '^\[mosgov\]' "$ART/27-restore-a.log" | tail -8
[[ "$RC" -ne 0 ]] && tail -20 "$ART/27-restore-a.log"

db_snapshot "$DB_A" "$WORK/db_a_after.txt"
if diff -q "$WORK/db_a_before.txt" "$WORK/db_a_after.txt" >/dev/null; then
    p8_ok "A 恢复后全部表行数与备份前一致（$(wc -l < "$WORK/db_a_before.txt") 张表）"
else
    p8_bad "A 恢复后表行数不一致"
    diff "$WORK/db_a_before.txt" "$WORK/db_a_after.txt" | head -10
fi
p8_eq "篡改标记在恢复后消失" "0" "$(sqlq "SELECT COUNT(*) FROM $DB_A.config_cfg WHERE cfg_name='p8_restore_marker'")"

p8_eq "恢复后 seed Admin 仍为停用（未被重新启用）" "1" \
    "$(sqlq "SELECT COUNT(*) FROM $DB_A.person_per WHERE per_ID=1 AND per_DateDeactivated IS NOT NULL")"
p8_eq "恢复未改写 seed 用户行（指纹一致）" "$SEEDROW_A" \
    "$(sqlq "SELECT SHA2(CONCAT_WS('|',usr_UserName,usr_Password,usr_Admin,usr_NeedPasswordChange),256)
             FROM $DB_A.user_usr WHERE usr_per_ID=1")"
p8_eq "恢复后正式管理员仍在且 usr_Admin=1" "1" \
    "$(sqlq "SELECT usr_Admin FROM $DB_A.user_usr WHERE usr_UserName='office'")"
p8_eq "恢复后 evidence 触发器仍为 3 个" "3" \
    "$(sqlq "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DB_A' AND EVENT_OBJECT_TABLE='gov_evidence_log'")"

# ---- 文件存储恢复（restore-files.sh）----------------------------------------
p8_hdr "S11c 文件存储恢复（files-*.tar.gz）"
RF_RC=0
mosgov-restore-files.sh --tenant "$TENANT_A" --file "$FILES_A" --yes --confirm RESTORE \
    > "$ART/31-restore-files-a.log" 2>&1 || RF_RC=$?
p8_exit "restore-files church-a" 0 "$RF_RC"
grep -E '^\[mosgov\]' "$ART/31-restore-files-a.log" | tail -6
[[ "$RF_RC" -ne 0 ]] && tail -15 "$ART/31-restore-files-a.log"

NFA2="$(file_snapshot "$STORAGE_A" "$WORK/files_a_after.txt")"
if diff -q "$WORK/files_a_before.txt" "$WORK/files_a_after.txt" >/dev/null; then
    p8_ok "A 恢复后文件数量与 sha256 与备份前一致（${NFA2} 个文件）"
else
    p8_bad "A 恢复后文件数量/哈希不一致（前 ${NFA} 后 ${NFA2}）"
    diff "$WORK/files_a_before.txt" "$WORK/files_a_after.txt" | head -10
fi
p8_eq "篡改文件在恢复后消失" "0" "$(test -e "$STORAGE_A/p8-dirty.txt" && echo 1 || echo 0)"
p8_eq "恢复后文件数与备份清单一致" \
    "$(jq -r '.files_archive.entries' "${BK_A%.sql.gz}.manifest.json")" "${NFA2:-0}"
p8_eq "恢复后存储属主为教会独立组" "mosgov-church-a:mosgov-church-a" "$(stat -c '%U:%G' "$STORAGE_A")"
p8_eq "恢复后存储权限 0700" "700" "$(stat -c '%a' "$STORAGE_A")"
p8_private_dir_after_restore "恢复后 A 私有存储" "$STORAGE_A" "$USER_A" "$USER_B"
for spec in \
    "恢复后 A PHP 临时目录|$TMP_A|$USER_A|$USER_B" \
    "恢复后 A PHP 会话目录|$SESS_A|$USER_A|$USER_B" \
    "恢复后 A PHP 日志目录|$LOGDIR_A|$USER_A|$USER_B"; do
    IFS='|' read -r label dir owner guest <<< "$spec"
    p8_private_dir_after_restore "$label" "$dir" "$owner" "$guest"
done

# B 必须完全不受 A 恢复影响
db_snapshot "$DB_B" "$WORK/db_b_after_a.txt"
diff -q "$WORK/db_b_before.txt" "$WORK/db_b_after_a.txt" >/dev/null \
    && p8_ok "A 恢复未影响 B 的任何表行数" || p8_bad "A 恢复影响了 B"

# 恢复后应用账号仍须能写业务表（最小权限 + 可写）
p8_hdr "S11b 恢复后应用账号可写性（最小权限重建）"
PW_A="$(awk -F= '$1=="MOSGOV_DB_PASSWORD"{print $2}' "/etc/mosgov/$TENANT_A/env")"
WRITE_RC=0
mariadb -h 127.0.0.1 -P 3306 -u "$DB_A" -p"$PW_A" "$DB_A" \
    -e "INSERT INTO config_cfg (cfg_name,cfg_value) VALUES ('p8_write_probe','1')
        ON DUPLICATE KEY UPDATE cfg_value='1';" >/dev/null 2>"$WORK/write.err" || WRITE_RC=$?
if [[ "$WRITE_RC" -eq 0 ]]; then
    p8_ok "恢复后应用账号可 INSERT 业务表 config_cfg"
    mariadb -h 127.0.0.1 -P 3306 -u "$DB_A" -p"$PW_A" "$DB_A" \
        -e "DELETE FROM config_cfg WHERE cfg_name='p8_write_probe';" >/dev/null 2>&1 || true
else
    p8_bad "恢复后应用账号无法写业务表（exit=$WRITE_RC）：$(head -1 "$WORK/write.err")"
fi
# 审计表仍不得可改
AUD_RC=0
mariadb -h 127.0.0.1 -P 3306 -u "$DB_A" -p"$PW_A" "$DB_A" \
    -e "UPDATE gov_evidence_log SET id=id WHERE 1=0;" >/dev/null 2>&1 || AUD_RC=$?
[[ "$AUD_RC" -ne 0 ]] && p8_ok "应用账号仍无法 UPDATE 审计表（最小权限保持）" \
    || p8_bad "应用账号竟可 UPDATE 审计表"
unset PW_A
stage_end

# =============================================================================
stage "S12 church-b 恢复（与 A 互不影响）"
# =============================================================================
RC=0
mosgov-restore-db.sh --tenant "$TENANT_B" --file "$BK_B" --yes --confirm RESTORE \
    > "$ART/28-restore-b.log" 2>&1 || RC=$?
p8_exit "restore church-b" 0 "$RC"
[[ "$RC" -ne 0 ]] && tail -20 "$ART/28-restore-b.log"
db_snapshot "$DB_B" "$WORK/db_b_after.txt"
diff -q "$WORK/db_b_before.txt" "$WORK/db_b_after.txt" >/dev/null \
    && p8_ok "B 恢复后全部表行数与备份前一致" || p8_bad "B 恢复后表行数不一致"
RFB=0
mosgov-restore-files.sh --tenant "$TENANT_B" --file "$FILES_B" --yes --confirm RESTORE \
    > "$ART/32-restore-files-b.log" 2>&1 || RFB=$?
p8_exit "restore-files church-b" 0 "$RFB"
[[ "$RFB" -ne 0 ]] && tail -15 "$ART/32-restore-files-b.log"
NFB2="$(file_snapshot "$STORAGE_B" "$WORK/files_b_after.txt")"
diff -q "$WORK/files_b_before.txt" "$WORK/files_b_after.txt" >/dev/null \
    && p8_ok "B 恢复后文件哈希一致（${NFB2} 个文件）" || p8_bad "B 恢复后文件不一致"
p8_private_dir_after_restore "恢复后 B 私有存储" "$STORAGE_B" "$USER_B" "$USER_A"
for spec in \
    "恢复后 B PHP 临时目录|$TMP_B|$USER_B|$USER_A" \
    "恢复后 B PHP 会话目录|$SESS_B|$USER_B|$USER_A" \
    "恢复后 B PHP 日志目录|$LOGDIR_B|$USER_B|$USER_A"; do
    IFS='|' read -r label dir owner guest <<< "$spec"
    p8_private_dir_after_restore "$label" "$dir" "$owner" "$guest"
done
db_snapshot "$DB_A" "$WORK/db_a_after_b.txt"
diff -q "$WORK/db_a_after.txt" "$WORK/db_a_after_b.txt" >/dev/null \
    && p8_ok "B 恢复未影响 A" || p8_bad "B 恢复影响了 A"
file_snapshot "$STORAGE_A" "$WORK/files_a_after_b.txt" >/dev/null
diff -q "$WORK/files_a_after.txt" "$WORK/files_a_after_b.txt" >/dev/null \
    && p8_ok "B 文件恢复未影响 A 的存储" || p8_bad "B 文件恢复影响了 A 的存储"
stage_end

# =============================================================================
stage "S13 恢复后再次全项 healthcheck"
# =============================================================================
HC2=0
mosgov-healthcheck.sh --all > "$ART/29-healthcheck-after-restore.log" 2>&1 || HC2=$?
p8_exit "恢复后 healthcheck --all" 0 "$HC2"
grep -E '^FAIL' "$ART/29-healthcheck-after-restore.log" || true
p8_eq "恢复后 healthcheck 失败项为 0" "0" "$(grep -c '^FAIL' "$ART/29-healthcheck-after-restore.log")"
export P8_HOST="$HOST_A"
ck_reset
p8_req GET /session/begin >/dev/null
p8_eq "恢复后管理员可用备份时口令登录（302）" "302" \
    "$(p8_req POST /session/begin --data-urlencode "User=office" --data-urlencode "Password=$NEWPW")"
p8_eq "恢复后管理员可进入仪表盘（200）" "200" "$(p8_req GET /v2/dashboard)"
stage_end

# =============================================================================
stage "S14 权限 / 敏感配置审计"
# =============================================================================
RC=0
mosgov-verify-secrets.sh > "$ART/30-verify-secrets.log" 2>&1 || RC=$?
p8_exit "verify-secrets.sh 全教会" 0 "$RC"
grep -E '^FAIL' "$ART/30-verify-secrets.log" || true

for T in "$TENANT_A" "$TENANT_B"; do
    p8_eq "$T 私有存储非世界可读" "0" \
        "$(php8.4 -r '$m=fileperms($argv[1]) & 0777; echo (($m & 0005) ? 1 : 0);' "/var/lib/mosgov/$T/files" 2>/dev/null)"
    p8_eq "$T 备份目录 0700 root:root" "0700 root:root" \
        "$(stat -c '%04a %U:%G' "/var/backups/mosgov/$T" 2>/dev/null)"
done
p8_eq "备份文件不可被其他用户读（0400）" "0" \
    "$(find /var/backups/mosgov -maxdepth 2 -name 'db-*.sql.gz' -not -perm 0400 | wc -l)"

# Web 根内不得出现数据库口令（Config.php 只读 env）
PW="$(awk -F= '$1=="MOSGOV_DB_PASSWORD"{print $2}' "/etc/mosgov/$TENANT_A/env")"
if [[ -n "$PW" ]] && grep -rqF "$PW" /var/www/mos-gov/current 2>/dev/null; then
    p8_bad "Web 根内发现数据库口令明文"
else
    p8_ok "Web 根内无数据库口令明文"
fi
unset PW
# grep -c 零命中时 exit 1，`|| echo 0` 会再补一个 0（输出 "0\n0"），改用 || true
CFG_PW_HITS="$(grep -cE '\$sPASSWORD[[:space:]]*=[[:space:]]*['\''\"][^'\''\"]+['\''\"]' /var/www/mos-gov/current/Include/Config.php 2>/dev/null || true)"
p8_eq "Config.php 不含硬编码口令" "0" "${CFG_PW_HITS:-0}"
unset CFG_PW_HITS
# 证据脚本与日志不得残留口令（密钥缺失时跳过，不算通过也不算失败）
HMAC_KEY="$(awk -F= '$1=="MOSGOV_AUDIT_HMAC_KEY"{print $2}' "/etc/mosgov/$TENANT_A/env")"
if [[ -z "$HMAC_KEY" ]]; then
    p8_skip "审计 HMAC 密钥泄漏检查（env 缺失，前置阶段失败）"
elif grep -rqF "$HMAC_KEY" /var/log/mosgov "$ART" 2>/dev/null; then
    p8_bad "审计 HMAC 密钥出现在日志/证据中"
else
    p8_ok "审计 HMAC 密钥未出现在日志与证据中"
fi
unset PW HMAC_KEY

# 收尾：销毁凭据文件
if [[ -f "$PASSFILE_A" ]]; then
    shred -u "$PASSFILE_A" 2>/dev/null || rm -f "$PASSFILE_A"
    [[ ! -e "$PASSFILE_A" ]] && p8_ok "临时管理员凭据已销毁" || p8_bad "临时凭据残留"
fi
chmod -R a+rX "$WORK" "$ART" 2>/dev/null || true
stage_end

# =============================================================================
printf '\n===== PHASE8 SUMMARY PASS=%d FAIL=%d SKIP=%d =====\n' "$P8_PASS" "$P8_FAIL" "$P8_SKIP"
[[ "$P8_FAIL" -eq 0 ]] && exit 0 || exit 1
