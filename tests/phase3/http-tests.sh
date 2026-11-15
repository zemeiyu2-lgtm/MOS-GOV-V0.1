#!/usr/bin/env bash
# =============================================================================
# MOS-GOV Phase 3 — HTTP-level security tests for the isolated lab front
# controller (tests/phase3/lab-http/router.php).
#
# Scope: the NEW public security components (transport trust, session flags,
# CSRF/origin, tenant-host binding, 401/403, security headers) over real HTTP.
# This is NOT a full ChurchCRM UI end-to-end test — the router does not boot
# ChurchCRM/Slim/Propel. See the delivery report for the explicit boundary.
# =============================================================================
set -uo pipefail

URL="${URL:-http://127.0.0.1:8099}"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
pass=0
fail=0

check() {
  local label="$1" expected="$2" actual="$3"
  if [[ "$expected" == "$actual" ]]; then
    echo "PASS | $label | $actual"; pass=$((pass+1))
  else
    echo "FAIL | $label | expected=$expected actual=$actual"; fail=$((fail+1))
  fi
}
contains() {
  local label="$1" needle="$2" file="$3"
  if grep -qi -- "$needle" "$file"; then
    echo "PASS | $label"; pass=$((pass+1))
  else
    echo "FAIL | $label | missing: $needle"; fail=$((fail+1))
  fi
}
req() {
  local method="$1" path="$2"; shift 2
  curl -sS -X "$method" "$URL$path" -D "$TMP/h" -o "$TMP/b" -w '%{http_code}' "$@" > "$TMP/code" 2>/dev/null
  cat "$TMP/code"
}
sid() { grep -i '^Set-Cookie:' "$TMP/h" | tail -1 | sed -nE 's/.*PHPSESSID=([^;[:space:]]+).*/\1/p'; }
csrf() { sed -nE 's/.*name="csrf_token" value="([0-9a-f]+)".*/\1/p' "$TMP/b" | head -1; }

ORIGIN=(-H "Origin: https://127.0.0.1:8099")

echo "=== HTTP-01 health + security headers ==="
code=$(req GET /health)
check "GET /health returns 200" "200" "$code"
contains "HSTS header present" "Strict-Transport-Security:" "$TMP/h"
contains "CSP header present" "Content-Security-Policy:" "$TMP/h"
contains "X-Frame-Options DENY" "X-Frame-Options: DENY" "$TMP/h"
contains "no-store cache policy" "Cache-Control: no-store" "$TMP/h"
contains "tenant is church-a" '"tenant":"church-a"' "$TMP/b"

echo "=== HTTP-02 unauthenticated API access ==="
code=$(req GET /api/governance)
check "anonymous GET /api/governance is 401" "401" "$code"
contains "401 response still carries CSP" "Content-Security-Policy:" "$TMP/h"
code=$(req POST /api/governance "${ORIGIN[@]}" -d 'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
check "anonymous POST /api/governance is 401" "401" "$code"

echo "=== HTTP-03 tenant/host binding ==="
code=$(req GET /health -H 'Host: evil.example')
check "request on an unregistered host is 403" "403" "$code"

echo "=== HTTP-04 login form + CSRF token ==="
code=$(req GET /login)
check "GET /login returns 200" "200" "$code"
SID1=$(sid); CSRF1=$(csrf)
contains "login form carries a CSRF token" 'name="csrf_token"' "$TMP/b"
contains "session cookie is HttpOnly" 'HttpOnly' "$TMP/h"
contains "session cookie is Secure" 'Secure' "$TMP/h"
contains "session cookie is SameSite=Lax" 'SameSite=Lax' "$TMP/h"

echo "=== HTTP-05 password verification ==="
COOKIE1=(-H "Cookie: PHPSESSID=$SID1")
code=$(req POST /login "${ORIGIN[@]}" "${COOKIE1[@]}" \
  --data-urlencode "csrf_token=$CSRF1" --data-urlencode 'username=lab-admin' --data-urlencode 'password=wrong-password')
check "wrong password is rejected with 401" "401" "$code"

code=$(req POST /login "${ORIGIN[@]}" "${COOKIE1[@]}" \
  --data-urlencode "csrf_token=$CSRF1" --data-urlencode 'username=lab-admin' --data-urlencode 'password=LabAdmin!2026')
check "correct password logs in with 200" "200" "$code"
SID2=$(sid)
if [[ -z "$SID2" ]]; then SID2="$SID1"; fi
if [[ "$SID2" != "$SID1" ]]; then
  echo "PASS | session id rotated at login | old=${SID1:0:8} new=${SID2:0:8}"; pass=$((pass+1))
else
  echo "FAIL | session id rotated at login | unchanged"; fail=$((fail+1))
fi

echo "=== HTTP-06 authenticated access + CSRF/origin enforcement ==="
COOKIE2=(-H "Cookie: PHPSESSID=$SID2")
code=$(req GET /api/governance "${COOKIE2[@]}")
check "authenticated GET /api/governance is 200" "200" "$code"
contains "response scoped to church-a" '"scope":"church-a-only"' "$TMP/b"

code=$(req POST /api/governance "${ORIGIN[@]}" "${COOKIE2[@]}" -d 'action=probe')
check "authenticated POST without CSRF token is 403" "403" "$code"

req GET /login "${COOKIE2[@]}" >/dev/null
CSRF2=$(csrf)
code=$(req POST /api/governance "${ORIGIN[@]}" "${COOKIE2[@]}" \
  --data-urlencode "csrf_token=$CSRF2" --data-urlencode 'action=probe')
check "authenticated POST with valid CSRF + same Origin is 200" "200" "$code"

code=$(req POST /api/governance "${COOKIE2[@]}" -H 'Origin: https://evil.example' \
  --data-urlencode "csrf_token=$CSRF2" --data-urlencode 'action=probe')
check "cross-origin POST with valid CSRF is 403" "403" "$code"

code=$(req POST /api/governance "${ORIGIN[@]}" "${COOKIE2[@]}" \
  -d 'csrf_token=<script>alert(1)</script>&action=probe')
check "malformed / script-like CSRF token is 403" "403" "$code"

echo "=== HTTP-07 logout ==="
code=$(req POST /logout "${ORIGIN[@]}" "${COOKIE2[@]}" --data-urlencode "csrf_token=$CSRF2")
check "logout returns 200" "200" "$code"
code=$(req GET /api/governance "${COOKIE2[@]}")
check "session is invalid after logout (401)" "401" "$code"

echo "--- HTTP suite: $pass PASS / $fail FAIL ---"
exit $((fail == 0 ? 0 : 1))
