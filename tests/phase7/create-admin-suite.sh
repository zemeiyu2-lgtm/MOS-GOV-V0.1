#!/usr/bin/env bash
# =============================================================================
# MOS-GOV Phase 7.1 —— mosgov-create-admin 隔离验收套件（LAB ONLY）
#
# 用隔离合成库（不含任何真实教会资料）验证 create-admin.php：
#   T1 首次创建成功 + 凭据核验 + 强制改密标志
#   T2 重复创建被拒绝（同名 / 已有非 seed 管理员）
#   T3 非法输入被拒绝（tenant / username / email / pass-file）
#   T4 跨租户访问被拒绝（env 与 --tenant 不一致 / gov_tenant 锚点不符）
#   T5 异常中断不留半账户（模拟失败注入）
#   T6 seed 账号与现有账户未被改动
#
# 依赖：本机 PHP 8.4（含 pdo_mysql）+ MariaDB，路径由环境变量提供。
# 凭据文件用后即 shred；明文口令绝不打印到 stdout。
# 退出码：全部 PASS=0，否则=1。
# =============================================================================
set -uo pipefail

PHP="${MOSGOV_CA_PHP:?set MOSGOV_CA_PHP}"
INI="${MOSGOV_CA_INI:?set MOSGOV_CA_INI}"
MYSQL="${MOSGOV_CA_MYSQL:?set MOSGOV_CA_MYSQL}"      # mariadb.exe
REPO="${MOSGOV_CA_REPO:?set MOSGOV_CA_REPO}"
LAB="${MOSGOV_CA_LAB:?set MOSGOV_CA_LAB}"
DBA_HOST="${MOSGOV_CA_DB_HOST:-127.0.0.1}"
DBA_PORT="${MOSGOV_CA_DB_PORT:-3407}"

SCRIPT="$REPO/deploy/scripts/create-admin.php"
VERIFY="$REPO/tests/phase7/verify-admin-credential.php"
ENVA="$LAB/church-a.env"
ENVB="$LAB/church-b.env"
ENVBAD="$LAB/church-b-wrongdb.env"   # tenant_id=church-b 但 tenant_db 指向 t1（锚点不符）

PASS=0; FAIL=0
ok()   { printf '  \033[32mPASS\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAIL=$((FAIL+1)); }
sqlq() { "$MYSQL" -h "$DBA_HOST" -P "$DBA_PORT" -u root -N -e "$1" 2>/dev/null; }

run_admin() { # run_admin <env> <passfile> <extra args...>
    local envf="$1"; local pf="$2"; shift 2
    "$PHP" -c "$INI" "$SCRIPT" --env "$envf" --pass-file "$pf" "$@" 2>/dev/null
}
field() { # field <json> <jq-less key> via php
    printf '%s' "$1" | "$PHP" -r '$j=json_decode(stream_get_contents(STDIN),true); $k=$argv[1]; echo $j[$k]??"";' "$2"
}

echo "== T1: 首次创建成功（church-a / labadmin） =="
P="$LAB/suite-t1.pass"; rm -f "$P"
O=$(run_admin "$ENVA" "$P" --tenant church-a --username labadmin --first-name Lab --last-name Admin --email lab@lab.invalid); RC=$?
[ "$RC" -eq 0 ] && ok "T1 exit=0" || bad "T1 exit=$RC ($(field "$O" reason))"
[ "$(field "$O" action)" = "created" ] && ok "T1 action=created" || bad "T1 action=$(field "$O" action)"
case "$O" in *'usr_Password'*|*"$("$PHP" -c "$INI" -r 'echo "";')"*) ;; esac
printf '%s' "$O" | grep -q '"password"' && bad "T1 JSON leaks password" || ok "T1 JSON has no password field"
V=$("$PHP" -c "$INI" "$VERIFY" --env "$ENVA" --pass-file "$P" --username labadmin --tenant church-a 2>/dev/null | tail -1)
[ "$V" = "RESULT PASS (0 failures)" ] && ok "T1 credential verification" || bad "T1 credential verification: $V"
shred -u "$P" 2>/dev/null || rm -f "$P"

echo "== T2: 重复创建被拒绝 =="
P2="$LAB/suite-t2.pass"; rm -f "$P2"
O=$(run_admin "$ENVA" "$P2" --tenant church-a --username labadmin); RC=$?
[ "$RC" -eq 4 ] && ok "T2a duplicate username exit=4" || bad "T2a exit=$RC"
[ "$(field "$O" reason)" = "username_exists" ] && ok "T2a reason=username_exists" || bad "T2a reason=$(field "$O" reason)"
[ ! -e "$P2" ] && ok "T2a no credential file written" || bad "T2a credential file exists"
O=$(run_admin "$ENVA" "$P2" --tenant church-a --username secondadmin); RC=$?
[ "$RC" -eq 4 ] && ok "T2b existing admin exit=4" || bad "T2b exit=$RC"
[ "$(field "$O" reason)" = "admin_exists" ] && ok "T2b reason=admin_exists" || bad "T2b reason=$(field "$O" reason)"
CNT=$(sqlq "SELECT COUNT(*) FROM mosgov_ca_t1.user_usr WHERE usr_per_ID<>1;")
[ "$CNT" = "1" ] && ok "T2 no extra admin created (count=$CNT)" || bad "T2 admin count=$CNT"

echo "== T3: 非法输入被拒绝 =="
TP="$LAB/suite-t3.pass"; rm -f "$TP"
run_admin "$ENVA" "$TP" --tenant "church-a' OR '1'='1" --username goodname >/dev/null 2>&1; RC=$?
[ "$RC" -eq 2 ] && ok "T3a malicious tenant exit=2" || bad "T3a exit=$RC"
run_admin "$ENVA" "$TP" --tenant church-b --username "x' OR 1=1 --" >/dev/null 2>&1; RC=$?
[ "$RC" -eq 2 ] && ok "T3b malicious username exit=2" || bad "T3b exit=$RC"
run_admin "$ENVA" "$TP" --tenant church-b --username validname --email "not-an-email" >/dev/null 2>&1; RC=$?
[ "$RC" -eq 2 ] && ok "T3c invalid email exit=2" || bad "T3c exit=$RC"
run_admin "$ENVA" "relative/path.pass" --tenant church-b --username validname >/dev/null 2>&1; RC=$?
[ "$RC" -eq 2 ] && ok "T3d relative pass-file exit=2" || bad "T3d exit=$RC"

echo "== T4: 跨租户访问被拒绝 =="
# 用 church-a 的 env 但声明 --tenant church-b：loadEnv 断言 env.TENANT_ID == tenant 失败
O=$(run_admin "$ENVA" "$TP" --tenant church-b --username crossuser); RC=$?
[ "$RC" -eq 3 ] && ok "T4a env/tenant mismatch exit=3" || bad "T4a exit=$RC ($(field "$O" reason))"
[ "$(field "$O" reason)" = "tenant_or_db_unavailable" ] && ok "T4a fail-closed reason" || bad "T4a reason=$(field "$O" reason)"
# env 声称 church-b / t1，但 t1 库内 gov_tenant.singleton 实为 church-a：锚点不符应拒绝。
# （生产环境的 gov_tenant.tenant_id 受 DB 触发器保护不可 UPDATE，故用错配 env 模拟。）
O=$(run_admin "$ENVBAD" "$TP" --tenant church-b --username crossuser); RC=$?
[ "$RC" -eq 3 ] && ok "T4b tenant anchor mismatch exit=3" || bad "T4b exit=$RC"
[ "$(field "$O" reason)" = "tenant_anchor_mismatch" ] && ok "T4b anchor fail-closed reason" || bad "T4b reason=$(field "$O" reason)"
# 目标 DB 中不得出现跨租户用户
CNT=$(sqlq "SELECT COUNT(*) FROM mosgov_ca_t1.user_usr WHERE usr_UserName='crossuser';")
[ "$CNT" = "0" ] && ok "T4 no cross-tenant account created" || bad "T4 cross-tenant account exists"

echo "== T5: 异常中断不留半账户（失败注入，使用干净的 church-b 库） =="
# 在 t2.person_per 上建 BEFORE INSERT 触发器抛错，使事务在插入 person 时失败。
# 注意：MariaDB 创建触发器必须先选中库，触发器名不可带库限定符。
sqlq "USE mosgov_ca_t2; DROP TRIGGER IF EXISTS fail_person;"
sqlq "USE mosgov_ca_t2; CREATE TRIGGER fail_person BEFORE INSERT ON person_per FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='lab fault injection';"
T5TRIG=$(sqlq "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='mosgov_ca_t2' AND TRIGGER_NAME='fail_person';")
[ "$T5TRIG" = "1" ] && ok "T5 fault-injection trigger installed" || bad "T5 trigger setup failed"
P5="$LAB/suite-t5.pass"; rm -f "$P5"
O=$(run_admin "$ENVB" "$P5" --tenant church-b --username faultuser); RC=$?
[ "$RC" -eq 5 ] && ok "T5 injected failure exit=5" || bad "T5 exit=$RC"
[ "$(field "$O" reason)" = "db_write_failed" ] && ok "T5 reason=db_write_failed" || bad "T5 reason=$(field "$O" reason)"
[ ! -e "$P5" ] && ok "T5 credential file removed on rollback" || bad "T5 credential file left behind"
CNT=$(sqlq "SELECT COUNT(*) FROM mosgov_ca_t2.user_usr WHERE usr_UserName='faultuser';")
[ "$CNT" = "0" ] && ok "T5 no half user row" || bad "T5 user row exists"
# person_per 应无新增（除 seed 外计数为 0）
CNT=$(sqlq "SELECT COUNT(*) FROM mosgov_ca_t2.person_per WHERE per_ID<>1;")
[ "$CNT" = "0" ] && ok "T5 no orphan person row" || bad "T5 orphan person row exists"
sqlq "USE mosgov_ca_t2; DROP TRIGGER IF EXISTS fail_person;"

echo "== T6: seed 账户与现有账户未被改动 =="
H=$(sqlq "SELECT COUNT(*) FROM mosgov_ca_t1.user_usr WHERE usr_per_ID=1 AND usr_UserName='Admin' AND usr_Admin=1 AND usr_NeedPasswordChange=1 AND usr_Password LIKE '\$2y\$12\$%';")
[ "$H" = "1" ] && ok "T6 seed Admin intact" || bad "T6 seed Admin changed"

echo
echo "SUMMARY PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
