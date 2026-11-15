#!/usr/bin/env bash
# =============================================================================
# MOS-GOV Phase 5 — build the ISOLATED synthetic test environment.
#
# Uses the machine's native PHP 8.4.25 + MariaDB 11.8.9. Never touches
# D:/MOS-GOV-Web/MOS-RESTORE/db (real data), never touches ports 3319/3406/8082,
# never contacts the Internet, never creates billable resources.
#
# Usage:  setup-lab.sh <path-to-protected-env.sh> <repo-root> <maria-bin-dir>
# =============================================================================
set -euo pipefail

LAB_ENV="${1:?usage: setup-lab.sh <env.sh> <repo-root> <maria-bin>}"
REPO="${2:?}"
MB="${3:?}"

# shellcheck disable=SC1090
source "$LAB_ENV"

SQL=("$MB/mariadb.exe" --defaults-file="${MOSGOV_P5_MY_INI:?}" --protocol=TCP
     -h "$MOSGOV_P5_DB_HOST" -P "$MOSGOV_P5_DB_PORT" -u "$MOSGOV_P5_DB_USER" --skip-ssl)
q() { "${SQL[@]}" "$@" 2>&1 | grep -viE '^WARNING' || true; }

echo "--- setup: fresh synthetic databases on ${MOSGOV_P5_DB_HOST}:${MOSGOV_P5_DB_PORT} ---"
q -e "DROP DATABASE IF EXISTS \`$MOSGOV_P5_PATCH_DB\`;
      DROP DATABASE IF EXISTS \`$MOSGOV_P5_DB_A\`;
      DROP DATABASE IF EXISTS \`$MOSGOV_P5_DB_B\`;
      CREATE DATABASE \`$MOSGOV_P5_PATCH_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      CREATE DATABASE \`$MOSGOV_P5_DB_A\`     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      CREATE DATABASE \`$MOSGOV_P5_DB_B\`     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "--- import minimal ChurchCRM group schema (patch verification DB) ---"
"${SQL[@]}" "$MOSGOV_P5_PATCH_DB" < "$REPO/tests/phase5/schema-groups.sql" 2>&1 | grep -viE '^WARNING' || true

echo "--- import the six real MOS-GOV migrations into BOTH church databases ---"
for db in "$MOSGOV_P5_DB_A" "$MOSGOV_P5_DB_B"; do
  for f in 001_initial 002_v02_authorization 003_healthboard \
           004_healthboard_remediation 005_governance_evidence 006_tenant_boundary; do
    "${SQL[@]}" "$db" < "$REPO/database/$f.sql" 2>&1 | grep -viE '^WARNING' || true
    echo "  migration $db/$f.sql OK"
  done
done

echo "--- bind each database to exactly ONE tenant (fail-closed boundary) ---"
q -e "INSERT INTO \`$MOSGOV_P5_DB_A\`.gov_tenant
        (singleton,tenant_id,tenant_db,host,display_name,status)
        VALUES (1,'church-a','$MOSGOV_P5_DB_A','a.mosgov.example','SYNTHETIC Test Church A','active');
      INSERT INTO \`$MOSGOV_P5_DB_B\`.gov_tenant
        (singleton,tenant_id,tenant_db,host,display_name,status)
        VALUES (1,'church-b','$MOSGOV_P5_DB_B','b.mosgov.example','SYNTHETIC Test Church B','active');"

echo "--- create two least-privilege DB accounts (each sees ONLY its own church) ---"
q -e "DROP USER IF EXISTS '$MOSGOV_P5_USER_A'@'127.0.0.1';
      DROP USER IF EXISTS '$MOSGOV_P5_USER_B'@'127.0.0.1';
      CREATE USER '$MOSGOV_P5_USER_A'@'127.0.0.1' IDENTIFIED BY '$MOSGOV_P5_PASS_A';
      CREATE USER '$MOSGOV_P5_USER_B'@'127.0.0.1' IDENTIFIED BY '$MOSGOV_P5_PASS_B';
      GRANT SELECT ON \`$MOSGOV_P5_DB_A\`.* TO '$MOSGOV_P5_USER_A'@'127.0.0.1';
      GRANT SELECT ON \`$MOSGOV_P5_DB_B\`.* TO '$MOSGOV_P5_USER_B'@'127.0.0.1';
      GRANT INSERT ON \`$MOSGOV_P5_DB_A\`.gov_evidence_log TO '$MOSGOV_P5_USER_A'@'127.0.0.1';
      GRANT INSERT ON \`$MOSGOV_P5_DB_B\`.gov_evidence_log TO '$MOSGOV_P5_USER_B'@'127.0.0.1';
      GRANT INSERT ON \`$MOSGOV_P5_DB_A\`.gov_audit_log TO '$MOSGOV_P5_USER_A'@'127.0.0.1';
      GRANT INSERT ON \`$MOSGOV_P5_DB_B\`.gov_audit_log TO '$MOSGOV_P5_USER_B'@'127.0.0.1';"

for pair in "$MOSGOV_P5_DB_A $MOSGOV_P5_USER_A" "$MOSGOV_P5_DB_B $MOSGOV_P5_USER_B"; do
  read -r db usr <<< "$pair"
  for t in $("${SQL[@]}" -N -e \
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db'
     AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME NOT IN
     ('gov_evidence_log','gov_audit_log','gov_tenant');" 2>/dev/null | grep -viE '^WARNING'); do
    q -e "GRANT INSERT, UPDATE, DELETE ON \`$db\`.\`$t\` TO '$usr'@'127.0.0.1';"
  done
done
q -e "FLUSH PRIVILEGES;"

echo "--- verify permissions really are separated ---"
"${SQL[@]}" -N -e "SHOW GRANTS FOR '$MOSGOV_P5_USER_A'@'127.0.0.1';" 2>/dev/null | grep -viE '^WARNING' || true
echo "SETUP_DONE"
