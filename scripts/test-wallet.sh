#!/usr/bin/env bash
set -eo pipefail

BASE_URL="http://localhost:8080"
PASSED=0
FAILED=0

assert_eq() {
  local expected="$1"
  local actual="$2"
  local msg="$3"
  if [ "$expected" != "$actual" ]; then
    echo "  ✘ FAIL: $msg (Expected '$expected', got '$actual')"
    FAILED=$((FAILED + 1))
    exit 1
  else
    echo "  ✔ PASS: $msg"
    PASSED=$((PASSED + 1))
  fi
}

assert_contains() {
  local haystack="$1"
  local needle="$2"
  local msg="$3"
  if [[ "$haystack" != *"$needle"* ]]; then
    echo "  ✘ FAIL: $msg (Did not find '$needle' in response)"
    FAILED=$((FAILED + 1))
    exit 1
  else
    echo "  ✔ PASS: $msg"
    PASSED=$((PASSED + 1))
  fi
}

echo "========================================================"
echo "      Running Phase 6 Wallet & Ledger Test Suite        "
echo "========================================================"

# Test 1: Unauthenticated access rejected with 401
echo "[Test 1] Unauthenticated request to /api/v1/wallet receives 401..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/v1/wallet")
assert_eq "401" "$HTTP_CODE" "Unauthenticated request correctly rejected with HTTP 401"

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/v1/wallet/topup" -H "Content-Type: application/json" -d '{"amount_cents": 1000}')
assert_eq "401" "$HTTP_CODE" "Unauthenticated topup rejected with HTTP 401"

# Test 2: Register fresh customer
RAND_STR=$(head -c 6 /dev/urandom | tr -dc 'a-z0-9')
CUST_USER="wal_cust_${RAND_STR}"
CUST_EMAIL="${CUST_USER}@test.local"
CUST_PASS="SecureWalletPass123!"

echo "[Test 2] Registering fresh customer: $CUST_USER..."
REG_RESP=$(curl -s -X POST "$BASE_URL/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\": \"$CUST_USER\", \"email\": \"$CUST_EMAIL\", \"password\": \"$CUST_PASS\"}")

CUST_TOKEN=$(echo "$REG_RESP" | jq -r '.data.token // empty')
CUST_ID=$(echo "$REG_RESP" | jq -r '.data.user.id // empty')
assert_contains "$CUST_ID" "usr_" "Customer created with valid ID ($CUST_ID)"

# Test 3: Check initial wallet state
echo "[Test 3] Checking fresh customer initial wallet state..."
WALLET_RESP=$(curl -s "$BASE_URL/api/v1/wallet" -H "Authorization: Bearer $CUST_TOKEN")
INIT_BAL=$(echo "$WALLET_RESP" | jq -r '.data.wallet.balance_cents')
INIT_CURR=$(echo "$WALLET_RESP" | jq -r '.data.wallet.currency')
INIT_FMT=$(echo "$WALLET_RESP" | jq -r '.data.wallet.formatted_balance')
INIT_TX_COUNT=$(echo "$WALLET_RESP" | jq -r '.data.total_transactions')

assert_eq "0" "$INIT_BAL" "Initial balance is strictly 0 cents"
assert_eq "EUR" "$INIT_CURR" "Default currency is EUR"
assert_eq "0.00 €" "$INIT_FMT" "Initial formatted balance is 0.00 €"
assert_eq "0" "$INIT_TX_COUNT" "Initial ledger transaction count is 0"

# Test 4: Validation on invalid amounts (422)
echo "[Test 4] Testing amount validation safeguards..."
RESP_NEG=$(curl -s -X POST "$BASE_URL/api/v1/wallet/topup" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount_cents": -500}')
ERR_CODE=$(echo "$RESP_NEG" | jq -r '.error.code // empty')
assert_eq "INVALID_AMOUNT" "$ERR_CODE" "Negative amount rejected with INVALID_AMOUNT"

RESP_ZERO=$(curl -s -X POST "$BASE_URL/api/v1/wallet/topup" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount_cents": 0}')
ERR_CODE=$(echo "$RESP_ZERO" | jq -r '.error.code // empty')
assert_eq "INVALID_AMOUNT" "$ERR_CODE" "Zero amount rejected with INVALID_AMOUNT"

RESP_BELOW_MIN=$(curl -s -X POST "$BASE_URL/api/v1/wallet/topup" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount_cents": 50}')
ERR_CODE=$(echo "$RESP_BELOW_MIN" | jq -r '.error.code // empty')
assert_eq "TOPUP_FAILED" "$ERR_CODE" "Sub-minimum amount (<100 cents) rejected"

RESP_ABOVE_MAX=$(curl -s -X POST "$BASE_URL/api/v1/wallet/topup" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount_cents": 2000000}')
ERR_CODE=$(echo "$RESP_ABOVE_MAX" | jq -r '.error.code // empty')
assert_eq "TOPUP_FAILED" "$ERR_CODE" "Above-maximum amount (>10,000 €) rejected"

# Test 5: Successful customer top-up (50.00 € = 5000 cents)
echo "[Test 5] Customer executes first top-up of 50.00 € (5000 cents)..."
TOPUP_RESP=$(curl -s -X POST "$BASE_URL/api/v1/wallet/topup" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount_cents": 5000, "description": "First funds deposit"}')

NEW_BAL=$(echo "$TOPUP_RESP" | jq -r '.data.wallet.balance_cents')
FMT_BAL=$(echo "$TOPUP_RESP" | jq -r '.data.wallet.formatted_balance')
TX_TYPE=$(echo "$TOPUP_RESP" | jq -r '.data.transaction.transaction_type')
TX_BAL_AFTER=$(echo "$TOPUP_RESP" | jq -r '.data.transaction.balance_after_cents')

assert_eq "5000" "$NEW_BAL" "Balance updated to 5000 cents"
assert_eq "50.00 €" "$FMT_BAL" "Formatted balance updated to 50.00 €"
assert_eq "CUSTOMER_TOPUP" "$TX_TYPE" "Transaction logged as CUSTOMER_TOPUP"
assert_eq "5000" "$TX_BAL_AFTER" "Ledger transaction recorded balance_after_cents = 5000"

# Test 6: Subsequent top-up of 25.00 € (2500 cents)
echo "[Test 6] Customer executes second top-up of 25.00 € (2500 cents)..."
TOPUP2_RESP=$(curl -s -X POST "$BASE_URL/api/v1/wallet/topup" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount_cents": 2500, "description": "Second funds deposit"}')

NEW_BAL2=$(echo "$TOPUP2_RESP" | jq -r '.data.wallet.balance_cents')
assert_eq "7500" "$NEW_BAL2" "Cumulative balance updated to 7500 cents (75.00 €)"

# Test 7: Strict Idempotency Key Replay Safeguard
echo "[Test 7] Verifying idempotency key prevents duplicate transactions..."
IDEMP_KEY="idemp_key_wallet_${RAND_STR}"

IDEMP_RESP1=$(curl -s -X POST "$BASE_URL/api/v1/wallet/topup" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMP_KEY" \
  -d '{"amount_cents": 1000, "description": "Idempotent top-up"}')

BAL_BEFORE_REPLAY=$(echo "$IDEMP_RESP1" | jq -r '.data.wallet.balance_cents')
REPLAY1=$(echo "$IDEMP_RESP1" | jq -r '.data.idempotent_replay')
assert_eq "8500" "$BAL_BEFORE_REPLAY" "Balance reached 8500 cents on first submission"
assert_eq "false" "$REPLAY1" "First request is not a replay"

# Send duplicate request with identical idempotency key
IDEMP_RESP2=$(curl -s -X POST "$BASE_URL/api/v1/wallet/topup" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMP_KEY" \
  -d '{"amount_cents": 1000, "description": "Duplicate idempotent top-up"}')

BAL_AFTER_REPLAY=$(echo "$IDEMP_RESP2" | jq -r '.data.wallet.balance_cents')
REPLAY2=$(echo "$IDEMP_RESP2" | jq -r '.data.idempotent_replay')
assert_eq "true" "$REPLAY2" "Duplicate request correctly recognized as idempotent replay"
assert_eq "8500" "$BAL_AFTER_REPLAY" "Balance preserved at exactly 8500 cents without double-crediting"

# Test 8: Paginated transaction ledger
echo "[Test 8] Testing paginated transaction ledger retrieval..."
TXS_PAGE1=$(curl -s "$BASE_URL/api/v1/wallet/transactions?limit=2&offset=0" \
  -H "Authorization: Bearer $CUST_TOKEN")
P1_COUNT=$(echo "$TXS_PAGE1" | jq -r '.data.transactions | length')
TOTAL_TXS=$(echo "$TXS_PAGE1" | jq -r '.data.pagination.total')
HAS_MORE=$(echo "$TXS_PAGE1" | jq -r '.data.pagination.has_more')

assert_eq "2" "$P1_COUNT" "Limit 2 returns exactly 2 transactions"
assert_eq "3" "$TOTAL_TXS" "Total recorded transactions count is 3"
assert_eq "true" "$HAS_MORE" "Pagination reports more items available"

TXS_PAGE2=$(curl -s "$BASE_URL/api/v1/wallet/transactions?limit=2&offset=2" \
  -H "Authorization: Bearer $CUST_TOKEN")
P2_COUNT=$(echo "$TXS_PAGE2" | jq -r '.data.transactions | length')
HAS_MORE2=$(echo "$TXS_PAGE2" | jq -r '.data.pagination.has_more')

assert_eq "1" "$P2_COUNT" "Offset 2 returns remaining 1 transaction"
assert_eq "false" "$HAS_MORE2" "Last page correctly indicates has_more = false"

# Test 9: Customer role blocked from partner credit
echo "[Test 9] Verifying customer is forbidden from partner crediting endpoint..."
FORBIDDEN_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/wallet/credit" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"identifier\": \"$CUST_USER\", \"amount_cents\": 1000}")
FORBIDDEN_CODE=$(echo "$FORBIDDEN_RESP" | jq -r '.error.code // empty')
assert_eq "FORBIDDEN" "$FORBIDDEN_CODE" "Customer blocked from partner credit with FORBIDDEN (HTTP 403)"

# Test 10: Partner logs in and credits customer (Dual-mutation + Debt Accounting)
echo "[Test 10] Partner logs in and credits customer account..."
PARTNER_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"partner","password":"PartnerPass123!"}')
PARTNER_TOKEN=$(echo "$PARTNER_LOGIN" | jq -r '.data.token // empty')
assert_contains "$PARTNER_TOKEN" "" "Partner successfully logged in"

# Query initial partner debt directly from backend db container
INITIAL_DEBT=$(docker compose exec -T webapp sqlite3 /data/backend.sqlite "SELECT debt_cents FROM partners WHERE user_id = (SELECT id FROM users WHERE username = 'partner');")
echo "  ℹ Partner debt before credit: ${INITIAL_DEBT:-0} cents"

PARTNER_CREDIT_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/wallet/credit" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"identifier\": \"$CUST_USER\", \"amount_cents\": 1500, \"notes\": \"Partner loyalty allocation\"}")

CREDIT_SUCCESS=$(echo "$PARTNER_CREDIT_RESP" | jq -r '.success')
assert_eq "true" "$CREDIT_SUCCESS" "Partner credit operation succeeded"

CUST_NEW_BAL=$(echo "$PARTNER_CREDIT_RESP" | jq -r '.data.customer_wallet.balance_cents')
assert_eq "10000" "$CUST_NEW_BAL" "Customer balance atomically increased to 10000 cents (100.00 €)"

# Verify partner debt increased by exactly 1500 cents (Rule 8)
UPDATED_DEBT=$(docker compose exec -T webapp sqlite3 /data/backend.sqlite "SELECT debt_cents FROM partners WHERE user_id = (SELECT id FROM users WHERE username = 'partner');")
EXPECTED_DEBT=$(( ${INITIAL_DEBT:-0} + 1500 ))
assert_eq "$EXPECTED_DEBT" "$UPDATED_DEBT" "Partner debt correctly incremented by 1500 cents"

# Verify partner billing ledger entry exists
BILLING_ENTRY_COUNT=$(docker compose exec -T webapp sqlite3 /data/backend.sqlite "SELECT count(*) FROM partner_billing_entries WHERE amount_cents = 1500 AND operation_type = 'CUSTOMER_TOPUP';")
if [ "$BILLING_ENTRY_COUNT" -gt 0 ]; then
  echo "  ✔ PASS: Partner billing entry recorded for customer top-up"
  PASSED=$((PASSED + 1))
else
  echo "  ✘ FAIL: Missing partner billing ledger entry"
  FAILED=$((FAILED + 1))
  exit 1
fi

# Verify customer ledger transaction has type PARTNER_TOPUP
CUST_LATEST_TX=$(curl -s "$BASE_URL/api/v1/wallet" -H "Authorization: Bearer $CUST_TOKEN")
LATEST_TX_TYPE=$(echo "$CUST_LATEST_TX" | jq -r '.data.recent_transactions[0].transaction_type')
LATEST_TX_REF=$(echo "$CUST_LATEST_TX" | jq -r '.data.recent_transactions[0].reference_type')
assert_eq "PARTNER_TOPUP" "$LATEST_TX_TYPE" "Latest transaction on customer ledger is PARTNER_TOPUP"
assert_eq "partner" "$LATEST_TX_REF" "Reference type is partner"

# Test 11: Partner customer listing
echo "[Test 11] Partner retrieves customer list with balances..."
CUST_LIST_RESP=$(curl -s "$BASE_URL/api/v1/partner/wallet/customers?search=$CUST_USER" \
  -H "Authorization: Bearer $PARTNER_TOKEN")
FOUND_USER=$(echo "$CUST_LIST_RESP" | jq -r '.data.customers[0].username // empty')
FOUND_BAL=$(echo "$CUST_LIST_RESP" | jq -r '.data.customers[0].balance_cents // empty')
assert_eq "$CUST_USER" "$FOUND_USER" "Partner search found newly created customer"
assert_eq "10000" "$FOUND_BAL" "Customer balance listed as 10000 cents"

# Test 12: Wallet Service Overdraft Protection (Deducting more than balance fails)
echo "[Test 12] Verifying overdraft protection in WalletService..."
OVERDRAFT_TEST=$(docker compose exec -T webapp php -r "
  require '/var/www/backend/vendor/autoload.php';
  \$ws = new App\Services\WalletService();
  try {
    \$ws->deductFunds('$CUST_ID', 999999, 'ORDER_PAYMENT', 'ord_test_fail', 'Excessive deduction');
    echo 'ALLOWED';
  } catch (Exception \$e) {
    echo 'BLOCKED: ' . \$e->getCode();
  }
")
assert_eq "BLOCKED: 402" "$OVERDRAFT_TEST" "Overdraft attempt blocked with HTTP 402 Insufficient Funds"

# Test 13: Financial Concurrency & Race Condition Validation
echo "[Test 13] Verifying financial integrity under concurrent wallet top-ups..."
CONC_START_BAL=$(curl -s "$BASE_URL/api/v1/wallet" -H "Authorization: Bearer $CUST_TOKEN" | jq -r '.data.wallet.balance_cents')

# Fire 5 concurrent requests with distinct idempotency keys
for i in {1..5}; do
  curl -s -X POST "$BASE_URL/api/v1/wallet/topup" \
    -H "Authorization: Bearer $CUST_TOKEN" \
    -H "Content-Type: application/json" \
    -H "Idempotency-Key: conc_${RAND_STR}_$i" \
    -d '{"amount_cents": 1000}' > /dev/null &
done
wait

CONC_END_BAL=$(curl -s "$BASE_URL/api/v1/wallet" -H "Authorization: Bearer $CUST_TOKEN" | jq -r '.data.wallet.balance_cents')
CONC_EXPECTED=$(( CONC_START_BAL + 5000 ))
assert_eq "$CONC_EXPECTED" "$CONC_END_BAL" "Concurrent top-ups preserved exact balance without lost updates"

echo "========================================================"
echo "    Phase 6 Wallet & Ledger Results: $PASSED passed, $FAILED failed"
echo "========================================================"
