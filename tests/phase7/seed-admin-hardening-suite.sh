#!/usr/bin/env bash
# =============================================================================
# MOS-GOV Phase 7.2.2 —— 默认 seed Admin 风险修复验收套件（LAB ONLY）
#
# 覆盖（不重复 Phase 7.1 的 26 项）：
#   R0 普通非管理员真实 HTTP 调用 DELETE /admin/api/database/reset → 403 且无数据变化
#   R1 seed Admin 停用后不能登录
#   R2 正式管理员登录并可进入管理界面（200）
#   R3 正式管理员首次登录被强制改密
#   R4 事务中途失败注入 → 新管理员与 seed 停用整体回滚
#   R5 幂等：重复执行安全拒绝、不产生重复管理员
#   R6 修改范围：只有 per_ID=1 的 seed 记录变化
#   R9 双租户隔离：操作 B 库不影响 A 库
#
# 依赖环境变量：
#   MOSGOV_SA_PHP MOSGOV_SA_INI MOSGOV_SA_MYSQL MOSGOV_SA_REPO MOSGOV_SA_WORK
#   MOSGOV_SA_ENVA MOSGOV_SA_ENVB
#   MOSGOV_SA_DB_A (默认 mosgov_ca_t1)  MOSGOV_SA_DB_B (默认 mosgov_ca_t2)
#   MOSGOV_SA_BASE (如 http://127.0.0.1:8098；缺省则跳过 R0/R1/R2/R3 并标 SKIP)
#
# 前置（HTTP 部分）：全栈 ChurchCRM 已把 Include/Config.php 指向 DB_A，且服务可达。
# 明文口令只在临时文件，脚本从不回显；用后即删。
# 退出码：全部 PASS/无 FAIL = 0，否则 1。
# =============================================================================
set -uo pipefail

PHP="${MOSGOV_SA_PHP:?set MOSGOV_SA_PHP}"
INI="${MOSGOV_SA_INI:?set MOSGOV_SA_INI}"
MY="${MOSGOV_SA_MYSQL:?set MOSGOV_SA_MYSQL}"
REPO="${MOSGOV_SA_REPO:?set MOSGOV_SA_REPO}"
WORK="${MOSGOV_SA_WORK:?set MOSGOV_SA_WORK}"
ENVA="${MOSGOV_SA_ENVA:?set MOSGOV_SA_ENVA}"
ENVB="${MOSGOV_SA_ENVB:?set MOSGOV_SA_ENVB}"
DB_A="${MOSGOV_SA_DB_A:-mosgov_ca_t1}"
DB_B="${MOSGOV_SA_DB_B:-mosgov_ca_t2}"
BASE="${MOSGOV_SA_BASE:-}"
# seed 默认口令绝不写入本文件；由调用方通过环境变量临时提供，未提供则跳过相关断言。
SEEDPW="${MOSGOV_SA_SEED_PASSWORD:-}"
SCRIPT="$REPO/deploy/scripts/create-admin.php"
HOST="${MOSGOV_SA_DB_HOST:-127.0.0.1}"
PORT="${MOSGOV_SA_DB_PORT:-3407}"
mkdir -p "$WORK"

export NO_PROXY='*'
PASS=0; FAIL=0; SKIP=0
ok()   { printf '  [PASS] %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  [FAIL] %s\n' "$1"; FAIL=$((FAIL+1)); }
skip() { printf '  [SKIP] %s\n' "$1"; SKIP=$((SKIP+1)); }
sqlq() { "$MY" -h "$HOST" -P "$PORT" -u root -N -e "$1" 2>/dev/null; }
run_admin() { local envf="$1" pf="$2"; shift 2; "$PHP" -c "$INI" "$SCRIPT" --env "$envf" --pass-file "$pf" "$@" 2>/dev/null; }
field() { printf '%s' "$1" | "$PHP" -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j[$argv[1]]??"";' "$2"; }
reset_db() {
  local db="$1"
  sqlq "DELETE FROM $db.user_usr WHERE usr_per_ID<>1"
  sqlq "DELETE FROM $db.person_per WHERE per_ID<>1"
  sqlq "UPDATE $db.person_per SET per_DateDeactivated=NULL WHERE per_ID=1"
  sqlq "UPDATE $db.user_usr SET usr_Admin=1, usr_NeedPasswordChange=1 WHERE usr_per_ID=1"
}
snap_users()  { sqlq "SELECT * FROM $1.user_usr ORDER BY usr_per_ID"; }
snap_persons(){ sqlq "SELECT * FROM $1.person_per ORDER BY per_ID"; }

# --- 前置：重置两库为「仅 seed」 -------------------------------------------------
reset_db "$DB_A"; reset_db "$DB_B"
sqlq "INSERT INTO $DB_A.config_cfg (cfg_name,cfg_value) VALUES ('sChurchName','Lab Church A (synthetic)') ON DUPLICATE KEY UPDATE cfg_value=VALUES(cfg_value)" >/dev/null
snap_users "$DB_A" > "$WORK/sa_a_users_0"; snap_persons "$DB_A" > "$WORK/sa_a_persons_0"

# =============================================================================
# HTTP 段（R0/R1/R2/R3）——需要 BASE
# =============================================================================
if [ -z "$BASE" ]; then
  skip "HTTP 段（R0/R1/R2/R3）未执行：MOSGOV_SA_BASE 未设置"
else
  CJ="$WORK/sa_cookies"; rm -f "$CJ"

  echo "== R0: 普通非管理员调用 database reset（真实 HTTP）=="
  # 合成非管理员（usr_Admin=0），口令写入临时文件
  MP="$WORK/sa_member.pass"; rm -f "$MP"
  MPLAIN=$("$PHP" -r 'echo bin2hex(random_bytes(16));')
  MHASH=$("$PHP" -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$MPLAIN")
  sqlq "INSERT INTO $DB_A.person_per (per_FirstName,per_LastName,per_Email,per_DateEntered,per_EnteredBy) VALUES ('Lab','Member','member@lab.invalid',NOW(),1)" >/dev/null
  MPID=$(sqlq "SELECT MAX(per_ID) FROM $DB_A.person_per")
  sqlq "INSERT INTO $DB_A.user_usr (usr_per_ID,usr_UserName,usr_Password,usr_NeedPasswordChange,usr_Admin,usr_AddRecords,usr_EditRecords,usr_DeleteRecords,usr_MenuOptions,usr_ManageGroups,usr_Finance,usr_Notes,usr_LastLogin,usr_LoginCount,usr_FailedLogins,usr_EditSelf) VALUES ($MPID,'samember','$MHASH',0,0,0,0,0,0,0,0,0,NOW(),0,0,0)" >/dev/null
  printf '%s\n' "$MPLAIN" > "$MP"
  unset MPLAIN MHASH
  [ "$(sqlq "SELECT COUNT(*) FROM $DB_A.user_usr WHERE usr_UserName='samember'")" = "1" ] && ok "R0 合成普通用户已创建" || bad "R0 合成普通用户创建失败"
  BEFORE=$(sqlq "SELECT CONCAT((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_A'),'/',(SELECT COUNT(*) FROM $DB_A.user_usr),'/',(SELECT COUNT(*) FROM $DB_A.person_per),'/',(SELECT COUNT(*) FROM $DB_A.config_cfg))")
  curl -s -o /dev/null -c "$CJ" "$BASE/session/begin" >/dev/null
  MLOGIN=$(tr -d '\r\n' < "$MP" | curl -s -D - -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/session/begin" \
    --data-urlencode "User=samember" --data-urlencode "Password@-")
  MLOC=$(grep -i '^Location:' <<<"$MLOGIN" | tr -d '\r' | awk '{print $2}')
  [ -n "$MLOC" ] && ok "R0 普通用户登录成功（302 $MLOC）" || bad "R0 普通用户登录失败（未建立会话）"
  RCODE=$(curl -s -o "$WORK/sa_r0_body" -w '%{http_code}' -b "$CJ" -c "$CJ" -X DELETE "$BASE/admin/api/database/reset")
  AFTER=$(sqlq "SELECT CONCAT((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_A'),'/',(SELECT COUNT(*) FROM $DB_A.user_usr),'/',(SELECT COUNT(*) FROM $DB_A.person_per),'/',(SELECT COUNT(*) FROM $DB_A.config_cfg))")
  [ "$RCODE" = "403" ] && ok "R0 reset 返回 403" || bad "R0 reset 返回 $RCODE（期望 403）"
  grep -q '"code":403' "$WORK/sa_r0_body" && ok "R0 响应为 JSON 403" || bad "R0 响应体非 JSON 403"
  [ "$BEFORE" = "$AFTER" ] && ok "R0 无数据变化 ($BEFORE)" || bad "R0 数据发生变化：$BEFORE -> $AFTER"

  echo "== R1 前置：seed 默认口令当前仍可登录（阳性对照）=="
  if [ -z "$SEEDPW" ]; then
    skip "R1pre/R1 seed 登录断言未执行：MOSGOV_SA_SEED_PASSWORD 未设置"
  else
    curl -s -o /dev/null -c "$CJ" "$BASE/session/begin" >/dev/null
    PRE=$(curl -s -D - -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/session/begin" \
      --data-urlencode "User=Admin" --data-urlencode "Password=$SEEDPW")
    PRELOC=$(grep -i '^Location:' <<<"$PRE" | tr -d '\r' | awk '{print $2}')
    [ "$PRELOC" = "/v2/dashboard" ] && ok "R1pre seed 默认口令登录成功（风险基线）" || bad "R1pre seed 登录异常 loc=$PRELOC"
  fi

  echo "== 创建正式管理员（church-a）=="
  PFA="$WORK/sa_admin_a.pass"; rm -f "$PFA"
  OA=$(run_admin "$ENVA" "$PFA" --tenant church-a --username churchadmin --first-name Church --last-name Steward --email admin@lab.invalid); RCA=$?
  [ "$RCA" -eq 0 ] && ok "create-admin A exit=0" || bad "create-admin A exit=$RCA ($(field "$OA" reason))"
  [ "$(field "$OA" seed_admin_disabled)" = "1" ] && ok "create-admin 报告 seed_admin_disabled" || bad "seed_admin_disabled=$(field "$OA" seed_admin_disabled)"
  SDA=$(sqlq "SELECT IFNULL(per_DateDeactivated,'NULL') FROM $DB_A.person_per WHERE per_ID=1")
  [ "$SDA" != "NULL" ] && ok "seed per_ID=1 已停用 ($SDA)" || bad "seed per_ID=1 仍启用"

  echo "== R1: seed 停用后不能登录 =="
  if [ -z "$SEEDPW" ]; then
    skip "R1 seed 登录断言未执行：MOSGOV_SA_SEED_PASSWORD 未设置"
  else
    curl -s -o /dev/null -c "$CJ" "$BASE/session/begin" >/dev/null
    R1=$(curl -s -D - -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/session/begin" \
      --data-urlencode "User=Admin" --data-urlencode "Password=$SEEDPW")
    R1LOC=$(grep -i '^Location:' <<<"$R1" | tr -d '\r' | awk '{print $2}')
    R1CODE=$(grep -m1 -oE 'HTTP/[0-9.]+ [0-9]+' <<<"$R1" | awk '{print $2}')
    [ "$R1CODE" = "200" ] && [ -z "$R1LOC" ] && ok "R1 seed 登录被拒（200，无跳转）" || bad "R1 seed 登录异常 code=$R1CODE loc=$R1LOC"
    D1=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" "$BASE/v2/dashboard")
    [ "$D1" = "302" ] && ok "R1 seed 会话未获管理界面（302）" || bad "R1 seed 会话 dashboard=$D1"
  fi

  echo "== R3: 正式管理员首次登录强制改密 =="
  CJ2="$WORK/sa_admin_cookies"; rm -f "$CJ2"
  curl -s -o /dev/null -c "$CJ2" "$BASE/session/begin" >/dev/null
  R3=$(tr -d '\r\n' < "$PFA" | curl -s -D - -o /dev/null -b "$CJ2" -c "$CJ2" -X POST "$BASE/session/begin" \
    --data-urlencode "User=churchadmin" --data-urlencode "Password@-")
  R3LOC=$(grep -i '^Location:' <<<"$R3" | tr -d '\r' | awk '{print $2}')
  [ "$R3LOC" = "/v2/dashboard" ] && ok "R3 登录成功（302 dashboard）" || bad "R3 登录 loc=$R3LOC"
  R3D=$(curl -s -D - -o /dev/null -b "$CJ2" -c "$CJ2" "$BASE/v2/dashboard")
  R3DLOC=$(grep -i '^Location:' <<<"$R3D" | tr -d '\r' | awk '{print $2}')
  case "$R3DLOC" in *"/v2/user/current/changepassword"*) ok "R3 被强制导向改密页" ;; *) bad "R3 dashboard loc=$R3DLOC" ;; esac

  echo "== R3b/R2: 通过真实表单改密后进入管理界面 =="
  PAGE=$(curl -s -b "$CJ2" -c "$CJ2" "$BASE/v2/user/current/changepassword")
  TOK=$(grep -oE 'name="csrf_token" value="[^"]+"' <<<"$PAGE" | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')
  [ -n "$TOK" ] && ok "R3b 获取 CSRF 令牌" || bad "R3b 未获取 CSRF 令牌"
  NEWPASS="P722-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')-Zq"
  CH=$(tr -d '\r\n' < "$PFA" | curl -s -D - -o /dev/null -b "$CJ2" -c "$CJ2" -X POST "$BASE/v2/user/current/changepassword" \
    --data-urlencode "csrf_token=$TOK" --data-urlencode "OldPassword@-" \
    --data-urlencode "NewPassword1=$NEWPASS" --data-urlencode "NewPassword2=$NEWPASS")
  CHLOC=$(grep -i '^Location:' <<<"$CH" | tr -d '\r' | awk '{print $2}')
  [ "$CHLOC" = "/v2/dashboard" ] && ok "R3b 改密成功（302 dashboard）" || bad "R3b 改密 loc=$CHLOC"
  R2=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ2" -c "$CJ2" "$BASE/v2/dashboard")
  [ "$R2" = "200" ] && ok "R2 正式管理员进入管理界面 200" || bad "R2 dashboard=$R2"
  unset NEWPASS

  snap_users "$DB_A" > "$WORK/sa_a_users_1"; snap_persons "$DB_A" > "$WORK/sa_a_persons_1"
fi

# =============================================================================
# R4 / R4b / R5 / R6 —— 在 B 库（church-b）上，不依赖 HTTP
# =============================================================================
echo "== R4: 事务中途失败注入 → 整体回滚 =="
reset_db "$DB_B"
sqlq "DROP TRIGGER IF EXISTS $DB_B.sa_fail_seed" >/dev/null
sqlq "CREATE TRIGGER $DB_B.sa_fail_seed BEFORE UPDATE ON $DB_B.person_per FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='sa fault injection'" >/dev/null
TRIG=$(sqlq "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DB_B' AND TRIGGER_NAME='sa_fail_seed'")
[ "$TRIG" = "1" ] && ok "R4 失败注入触发器已装" || bad "R4 触发器安装失败"
PF4="$WORK/sa_r4.pass"; rm -f "$PF4"
O4=$(run_admin "$ENVB" "$PF4" --tenant church-b --username faultadmin); RC4=$?
[ "$RC4" -eq 5 ] && ok "R4 exit=5（DB 写入失败已回滚）" || bad "R4 exit=$RC4 ($(field "$O4" reason))"
[ ! -e "$PF4" ] && ok "R4 凭据文件已销毁" || bad "R4 凭据文件残留"
[ "$(sqlq "SELECT COUNT(*) FROM $DB_B.user_usr WHERE usr_UserName='faultadmin'")" = "0" ] && ok "R4 无半创建用户" || bad "R4 存在 faultadmin"
[ "$(sqlq "SELECT COUNT(*) FROM $DB_B.person_per WHERE per_ID<>1")" = "0" ] && ok "R4 无孤立 person" || bad "R4 存在孤立 person"
[ "$(sqlq "SELECT IFNULL(per_DateDeactivated,'NULL') FROM $DB_B.person_per WHERE per_ID=1")" = "NULL" ] && ok "R4 seed 停用已回滚（仍启用）" || bad "R4 seed 被误停用"
sqlq "DROP TRIGGER IF EXISTS $DB_B.sa_fail_seed" >/dev/null

echo "== R4b: seed 身份不符必须 fail-closed（exit 7）=="
reset_db "$DB_B"
sqlq "UPDATE $DB_B.person_per SET per_FirstName='Renamed' WHERE per_ID=1" >/dev/null
PF4B="$WORK/sa_r4b.pass"; rm -f "$PF4B"
O4B=$(run_admin "$ENVB" "$PF4B" --tenant church-b --username renamedtest); RC4B=$?
[ "$RC4B" -eq 7 ] && ok "R4b exit=7（seed 身份未核实）" || bad "R4b exit=$RC4B ($(field "$O4B" reason))"
[ "$(field "$O4B" reason)" = "seed_admin_verification_failed" ] && ok "R4b reason=seed_admin_verification_failed" || bad "R4b reason=$(field "$O4B" reason)"
[ "$(sqlq "SELECT COUNT(*) FROM $DB_B.user_usr WHERE usr_UserName='renamedtest'")" = "0" ] && ok "R4b 未创建账户" || bad "R4b 创建了账户"
sqlq "UPDATE $DB_B.person_per SET per_FirstName='Church' WHERE per_ID=1" >/dev/null

echo "== R6 + R5: 干净运行、修改范围与幂等 =="
reset_db "$DB_B"
snap_users "$DB_B" > "$WORK/sa_b_users_0"; snap_persons "$DB_B" > "$WORK/sa_b_persons_0"
PF5="$WORK/sa_r5.pass"; rm -f "$PF5"
O5=$(run_admin "$ENVB" "$PF5" --tenant church-b --username badmin --first-name Church --last-name Bee --email b@lab.invalid); RC5=$?
[ "$RC5" -eq 0 ] && ok "R6 create-admin B exit=0" || bad "R6 exit=$RC5 ($(field "$O5" reason))"
snap_users "$DB_B" > "$WORK/sa_b_users_1"; snap_persons "$DB_B" > "$WORK/sa_b_persons_1"
# seed user 行字节不变
PRE_SEED=$(awk -F'\t' '$1=="1"' "$WORK/sa_b_users_0")
POST_SEED=$(awk -F'\t' '$1=="1"' "$WORK/sa_b_users_1")
[ "$PRE_SEED" = "$POST_SEED" ] && ok "R6 seed 用户行未变" || bad "R6 seed 用户行被改"
# 仅 per_ID=1 变化
CHANGED=$(awk -F'\t' 'NR==FNR{b[$1]=$0;next} ($1 in b)&&(b[$1]!=$0){print $1}' "$WORK/sa_b_persons_0" "$WORK/sa_b_persons_1" | tr '\n' ',')
[ "$CHANGED" = "1," ] && ok "R6 仅 per_ID=1 变化" || bad "R6 变化行=$CHANGED"
# 新增恰好 1 person + 1 user
NEWP=$(awk -F'\t' 'NR==FNR{b[$1]=1;next} !($1 in b){c++} END{print c+0}' "$WORK/sa_b_persons_0" "$WORK/sa_b_persons_1")
NEWU=$(awk -F'\t' 'NR==FNR{b[$1]=1;next} !($1 in b){c++} END{print c+0}' "$WORK/sa_b_users_0" "$WORK/sa_b_users_1")
[ "$NEWP" = "1" ] && ok "R6 新增 person=1" || bad "R6 新增 person=$NEWP"
[ "$NEWU" = "1" ] && ok "R6 新增 user=1" || bad "R6 新增 user=$NEWU"
# 幂等
PF5B="$WORK/sa_r5b.pass"; rm -f "$PF5B"
O5B=$(run_admin "$ENVB" "$PF5B" --tenant church-b --username secondone); RC5B=$?
[ "$RC5B" -eq 4 ] && ok "R5 重复运行 exit=4" || bad "R5 exit=$RC5B"
[ "$(field "$O5B" reason)" = "admin_exists" ] && ok "R5 reason=admin_exists" || bad "R5 reason=$(field "$O5B" reason)"
[ ! -e "$PF5B" ] && ok "R5 未写凭据文件" || bad "R5 凭据文件残留"
snap_users "$DB_B" > "$WORK/sa_b_users_2"; snap_persons "$DB_B" > "$WORK/sa_b_persons_2"
diff -q "$WORK/sa_b_users_1" "$WORK/sa_b_users_2" >/dev/null && ok "R5 用户表未变" || bad "R5 用户表变化"
diff -q "$WORK/sa_b_persons_1" "$WORK/sa_b_persons_2" >/dev/null && ok "R5 人员表未变" || bad "R5 人员表变化"

# =============================================================================
# R9: 对 B 的全部操作不得影响 A
# =============================================================================
echo "== R9: 双租户隔离 =="
if [ -n "$BASE" ]; then
  snap_users "$DB_A" > "$WORK/sa_a_users_2"; snap_persons "$DB_A" > "$WORK/sa_a_persons_2"
  diff -q "$WORK/sa_a_users_1" "$WORK/sa_a_users_2" >/dev/null && ok "R9 A 用户表未被 B 操作影响" || bad "R9 A 用户表变化"
  diff -q "$WORK/sa_a_persons_1" "$WORK/sa_a_persons_2" >/dev/null && ok "R9 A 人员表未被 B 操作影响" || bad "R9 A 人员表变化"
else
  # 无 HTTP 时，A 只做过 reset；对比 A 初始快照与当前（B 操作后）
  snap_users "$DB_A" > "$WORK/sa_a_users_2"; snap_persons "$DB_A" > "$WORK/sa_a_persons_2"
  diff -q "$WORK/sa_a_users_0" "$WORK/sa_a_users_2" >/dev/null && ok "R9 A 用户表未被 B 操作影响" || bad "R9 A 用户表变化"
  diff -q "$WORK/sa_a_persons_0" "$WORK/sa_a_persons_2" >/dev/null && ok "R9 A 人员表未被 B 操作影响" || bad "R9 A 人员表变化"
fi

echo
echo "SUMMARY PASS=$PASS FAIL=$FAIL SKIP=$SKIP"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
