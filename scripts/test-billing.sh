#!/usr/bin/env bash
# ==============================================================================
# Phase 7: Partner Billing & Debt Settlement Integration Test Suite
# Tests partner debt ledger, admin settlement, statements, and invoice retention
# ==============================================================================
set -e

BASE_URL="http://localhost:8080"
PASSED=0
FAILED=0

assert_eq() {
  local expected="$1"
  local actual="$2"
  local msg="$3"
  if [ "$expected" == "$actual" ]; then
    echo "  ✔ PASS: $msg"
    PASSED=$((PASSED + 1))
  else
    echo "  ✘ FAIL: $msg (Expected: '$expected', Got: '$actual')"
    FAILED=$((FAILED + 1))
    exit 1
  fi
}

assert_contains() {
  local haystack="$1"
  local needle="$2"
  local msg="$3"
  if [[ "$haystack" == *"$needle"* ]]; then
    echo "  ✔ PASS: $msg"
    PASSED=$((PASSED + 1))
  else
    echo "  ✘ FAIL: $msg (Expected to contain: '$needle')"
    FAILED=$((FAILED + 1))
    exit 1
  fi
}

echo "========================================================"
echo "    Running Phase 7 Partner Billing Test Suite          "
echo "========================================================"

# Test 1: Unauthenticated requests
echo "[Test 1] Verifying unauthenticated requests receive HTTP 401..."
RESP1=$(curl -s "$BASE_URL/api/v1/partner/billing")
CODE1=$(echo "$RESP1" | jq -r '.error.code // empty')
assert_eq "UNAUTHORIZED" "$CODE1" "Unauthenticated request to /partner/billing rejected with UNAUTHORIZED (401)"

RESP2=$(curl -s -X POST "$BASE_URL/api/v1/admin/billing/settle" -H "Content-Type: application/json" -d '{"amount_cents": 100}')
CODE2=$(echo "$RESP2" | jq -r '.error.code // empty')
assert_eq "UNAUTHORIZED" "$CODE2" "Unauthenticated request to /admin/billing/settle rejected with UNAUTHORIZED (401)"

# Test 2: Customer role authorization gate
echo "[Test 2] Verifying customer is blocked from partner billing & admin settle..."
CUST_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"customer","password":"CustomerPass123!"}')
CUST_TOKEN=$(echo "$CUST_LOGIN" | jq -r '.data.token // empty')
assert_contains "$CUST_TOKEN" "" "Customer successfully logged in"

CUST_BILLING=$(curl -s "$BASE_URL/api/v1/partner/billing" -H "Authorization: Bearer $CUST_TOKEN")
CUST_CODE=$(echo "$CUST_BILLING" | jq -r '.error.code // empty')
assert_eq "FORBIDDEN" "$CUST_CODE" "Customer blocked from partner billing with FORBIDDEN (HTTP 403)"

CUST_SETTLE=$(curl -s -X POST "$BASE_URL/api/v1/admin/billing/settle" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"partner_id":"dummy","amount_cents": 100}')
CUST_SETTLE_CODE=$(echo "$CUST_SETTLE" | jq -r '.error.code // empty')
assert_eq "FORBIDDEN" "$CUST_SETTLE_CODE" "Customer blocked from admin settle with FORBIDDEN (HTTP 403)"

# Test 3: Partner logs in and queries billing summary
echo "[Test 3] Partner logs in and retrieves billing summary..."
PARTNER_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"partner","password":"PartnerPass123!"}')
PARTNER_TOKEN=$(echo "$PARTNER_LOGIN" | jq -r '.data.token // empty')
assert_contains "$PARTNER_TOKEN" "" "Partner successfully logged in"

PARTNER_SUMMARY=$(curl -s "$BASE_URL/api/v1/partner/billing" -H "Authorization: Bearer $PARTNER_TOKEN")
SUMMARY_SUCCESS=$(echo "$PARTNER_SUMMARY" | jq -r '.success')
assert_eq "true" "$SUMMARY_SUCCESS" "Partner billing summary query succeeded"

PARTNER_ID=$(echo "$PARTNER_SUMMARY" | jq -r '.data.partner.id')
assert_contains "$PARTNER_ID" "par_" "Valid partner ID returned"

PARTNER_DEBT=$(echo "$PARTNER_SUMMARY" | jq -r '.data.partner.debt_cents')
TOTAL_CHARGES=$(echo "$PARTNER_SUMMARY" | jq -r '.data.total_charges_cents')
TOTAL_PAYMENTS=$(echo "$PARTNER_SUMMARY" | jq -r '.data.total_payments_cents')
CALC_DEBT=$(echo "$PARTNER_SUMMARY" | jq -r '.data.calculated_debt_cents')
EXPECTED_DEBT=$(( TOTAL_CHARGES - TOTAL_PAYMENTS ))

assert_eq "$EXPECTED_DEBT" "$CALC_DEBT" "Calculated debt correctly equals total_charges - total_payments"
assert_eq "$EXPECTED_DEBT" "$PARTNER_DEBT" "Partner table debt_cents matches ledger calculated debt"

# Test 4: Invoice retention toggle setting
echo "[Test 4] Testing invoice retention setting toggle..."
SETTING_OFF=$(curl -s -X POST "$BASE_URL/api/v1/partner/settings/invoice-retention" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"enabled": false}')
OFF_VAL=$(echo "$SETTING_OFF" | jq -r '.data.partner.invoice_retention_enabled')
assert_eq "false" "$OFF_VAL" "Invoice retention successfully disabled"

SETTING_ON=$(curl -s -X POST "$BASE_URL/api/v1/partner/settings/invoice-retention" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"enabled": true}')
ON_VAL=$(echo "$SETTING_ON" | jq -r '.data.partner.invoice_retention_enabled')
assert_eq "true" "$ON_VAL" "Invoice retention successfully re-enabled"

# Test 5: Partner queries paginated billing entries
echo "[Test 5] Querying partner billing entries ledger..."
ENTRIES_RESP=$(curl -s "$BASE_URL/api/v1/partner/billing/entries?limit=5&offset=0" \
  -H "Authorization: Bearer $PARTNER_TOKEN")
ENTRIES_SUCCESS=$(echo "$ENTRIES_RESP" | jq -r '.success')
assert_eq "true" "$ENTRIES_SUCCESS" "Billing entries query succeeded"

ENTRIES_COUNT=$(echo "$ENTRIES_RESP" | jq -r '.data.entries | length')
if [ "$ENTRIES_COUNT" -gt 0 ]; then
  FIRST_SIGN=$(echo "$ENTRIES_RESP" | jq -r '.data.entries[0].formatted_amount')
  assert_contains "$FIRST_SIGN" "+" "Billing charges format with positive sign (+X.XX €)"
fi

# Test 6: Admin logs in and views all partner debts
echo "[Test 6] Admin logs in and queries partner debts..."
ADMIN_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"admin","password":"AdminPass123!"}')
ADMIN_TOKEN=$(echo "$ADMIN_LOGIN" | jq -r '.data.token // empty')
assert_contains "$ADMIN_TOKEN" "" "Admin successfully logged in"

ADMIN_PARTNERS=$(curl -s "$BASE_URL/api/v1/admin/billing/partners" \
  -H "Authorization: Bearer $ADMIN_TOKEN")
ADMIN_SUCCESS=$(echo "$ADMIN_PARTNERS" | jq -r '.success')
assert_eq "true" "$ADMIN_SUCCESS" "Admin partners query succeeded"

FOUND_PARTNER=$(echo "$ADMIN_PARTNERS" | jq -r ".data.partners[] | select(.id == \"$PARTNER_ID\") | .id")
assert_eq "$PARTNER_ID" "$FOUND_PARTNER" "Admin view contains partner $PARTNER_ID"

# Test 7: Payment settlement validation
echo "[Test 7] Testing debt settlement validation rules..."
# Zero amount
VAL_ZERO=$(curl -s -X POST "$BASE_URL/api/v1/admin/billing/settle" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"partner_id\": \"$PARTNER_ID\", \"amount_cents\": 0}")
CODE_ZERO=$(echo "$VAL_ZERO" | jq -r '.error.code // empty')
assert_eq "INVALID_AMOUNT" "$CODE_ZERO" "Zero settlement amount rejected with INVALID_AMOUNT (HTTP 422)"

# Negative amount
VAL_NEG=$(curl -s -X POST "$BASE_URL/api/v1/admin/billing/settle" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"partner_id\": \"$PARTNER_ID\", \"amount_cents\": -500}")
CODE_NEG=$(echo "$VAL_NEG" | jq -r '.error.code // empty')
assert_eq "INVALID_AMOUNT" "$CODE_NEG" "Negative settlement amount rejected with INVALID_AMOUNT (HTTP 422)"

# Overpayment
VAL_OVER=$(curl -s -X POST "$BASE_URL/api/v1/admin/billing/settle" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"partner_id\": \"$PARTNER_ID\", \"amount_cents\": 99999999}")
CODE_OVER=$(echo "$VAL_OVER" | jq -r '.error.code // empty')
assert_eq "SETTLEMENT_FAILED" "$CODE_OVER" "Excess settlement amount exceeding debt rejected with HTTP 422"

# Test 8: Admin partial payment settlement
echo "[Test 8] Admin records partial payment settlement of 10.00 € (1000 cents)..."
# Ensure partner has at least 1000 cents debt by topping up customer if needed
CURRENT_DEBT=$(curl -s "$BASE_URL/api/v1/partner/billing" -H "Authorization: Bearer $PARTNER_TOKEN" | jq -r '.data.partner.debt_cents')
if [ "$CURRENT_DEBT" -lt 1000 ]; then
  echo "  ℹ Topping up customer wallet to generate sufficient partner debt..."
  curl -s -X POST "$BASE_URL/api/v1/partner/wallet/credit" \
    -H "Authorization: Bearer $PARTNER_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"identifier":"customer","amount_cents":2000}' > /dev/null
  CURRENT_DEBT=$(curl -s "$BASE_URL/api/v1/partner/billing" -H "Authorization: Bearer $PARTNER_TOKEN" | jq -r '.data.partner.debt_cents')
fi

# Count historical billing entries before payment
ENTRIES_BEFORE=$(docker compose exec -T webapp sqlite3 /data/backend.sqlite "SELECT count(*) FROM partner_billing_entries WHERE partner_id = '$PARTNER_ID';")

SETTLE_KEY="settle_audit_key_${RANDOM}_$(date +%s)"
SETTLE_RESP=$(curl -s -X POST "$BASE_URL/api/v1/admin/billing/settle" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $SETTLE_KEY" \
  -d "{\"partner_id\": \"$PARTNER_ID\", \"amount_cents\": 1000, \"payment_method\": \"WIRE\", \"reference_number\": \"WIRE-2026-001\", \"notes\": \"Verified wire settlement\"}")

SETTLE_OK=$(echo "$SETTLE_RESP" | jq -r '.success')
assert_eq "true" "$SETTLE_OK" "Payment settlement succeeded"

NEW_DEBT=$(echo "$SETTLE_RESP" | jq -r '.data.partner.debt_cents')
EXPECTED_NEW=$(( CURRENT_DEBT - 1000 ))
assert_eq "$EXPECTED_NEW" "$NEW_DEBT" "Partner debt accurately reduced by 1000 cents"

PAY_AMOUNT=$(echo "$SETTLE_RESP" | jq -r '.data.payment.amount_cents')
assert_eq "1000" "$PAY_AMOUNT" "Payment recorded with exact amount 1000 cents"

PAY_METHOD=$(echo "$SETTLE_RESP" | jq -r '.data.payment.payment_method')
assert_eq "WIRE" "$PAY_METHOD" "Payment recorded with payment method WIRE"

# Acceptance criterion 22: Historical billing entries remain intact
ENTRIES_AFTER=$(docker compose exec -T webapp sqlite3 /data/backend.sqlite "SELECT count(*) FROM partner_billing_entries WHERE partner_id = '$PARTNER_ID';")
assert_eq "$ENTRIES_BEFORE" "$ENTRIES_AFTER" "Historical billing entries strictly preserved without deletion"

# Test 9: Idempotency replay prevents double deduction
echo "[Test 9] Replaying identical settlement with same Idempotency-Key..."
REPLAY_RESP=$(curl -s -X POST "$BASE_URL/api/v1/admin/billing/settle" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $SETTLE_KEY" \
  -d "{\"partner_id\": \"$PARTNER_ID\", \"amount_cents\": 1000, \"payment_method\": \"WIRE\", \"reference_number\": \"WIRE-2026-001\", \"notes\": \"Verified wire settlement\"}")

IS_REPLAY=$(echo "$REPLAY_RESP" | jq -r '.data.idempotent_replay')
REPLAY_DEBT=$(echo "$REPLAY_RESP" | jq -r '.data.partner.debt_cents')
assert_eq "true" "$IS_REPLAY" "Server correctly identified idempotent replay"
assert_eq "$EXPECTED_NEW" "$REPLAY_DEBT" "Debt preserved without double-deduction on replay"

# Test 10: Statements generation
echo "[Test 10] Partner generates periodic billing statement..."
STM_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/billing/statements/generate" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"notes": "Automated monthly accounting reconciliation"}')
STM_SUCCESS=$(echo "$STM_RESP" | jq -r '.success')
assert_eq "true" "$STM_SUCCESS" "Statement generation succeeded"

STM_PERIOD=$(echo "$STM_RESP" | jq -r '.data.statement.statement_period')
assert_contains "$STM_PERIOD" "$(date +%Y-%m)" "Statement period matches current year-month"

STM_LIST=$(curl -s "$BASE_URL/api/v1/partner/billing/statements" -H "Authorization: Bearer $PARTNER_TOKEN")
STM_COUNT=$(echo "$STM_LIST" | jq -r '.data.statements | length')
assert_contains "$STM_COUNT" "1" "Generated statement appears in partner statements list"

echo "========================================================"
echo "    Phase 7 Partner Billing Results: $PASSED passed, $FAILED failed"
echo "========================================================"
