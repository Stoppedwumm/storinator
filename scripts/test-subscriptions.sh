#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
PASSED=0
FAILED=0

echo "========================================================"
echo "    Running Phase 5 Subscriptions & Quota Test Suite    "
echo "========================================================"

fail() {
  echo "  ❌ FAIL: $1"
  FAILED=$((FAILED + 1))
}

pass() {
  echo "  ✔ PASS: $1"
  PASSED=$((PASSED + 1))
}

# 1. Unauthenticated request rejection
echo "[Test 1] Unauthenticated request to /subscriptions/current receives 401..."
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/subscriptions/current")
if [ "${HTTP_STATUS}" -eq 401 ]; then
  pass "Unauthenticated request correctly rejected with HTTP 401"
else
  fail "Expected HTTP 401, got ${HTTP_STATUS}"
fi

# 2. Register fresh Customer user
RAND_SUFFIX=$(head /dev/urandom | tr -dc a-z0-9 | head -c 6)
CUST_USER="sub_cust_${RAND_SUFFIX}"
CUST_EMAIL="sub_cust_${RAND_SUFFIX}@test.local"
CUST_PASS="SecureSubPass123!"

echo "[Test 2] Registering fresh customer: ${CUST_USER}..."
REG_RES=$(curl -s -X POST "${BASE_URL}/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"${CUST_USER}\",\"email\":\"${CUST_EMAIL}\",\"password\":\"${CUST_PASS}\"}")

CUST_TOKEN=$(echo "${REG_RES}" | jq -r '.data.token // empty')
CUST_ID=$(echo "${REG_RES}" | jq -r '.data.user.id // empty')

if [ -n "${CUST_TOKEN}" ] && [ -n "${CUST_ID}" ]; then
  pass "Created and authenticated customer: ${CUST_ID}"
else
  fail "Failed to register customer: ${REG_RES}"
  exit 1
fi

CUST_AUTH="Authorization: Bearer ${CUST_TOKEN}"

# 3. Check subscription status of fresh customer (must be inactive)
echo "[Test 3] Checking initial subscription status..."
STATUS_RES=$(curl -s -X GET "${BASE_URL}/api/v1/subscriptions/current" -H "${CUST_AUTH}")
IS_ACTIVE=$(echo "${STATUS_RES}" | jq -r '.is_active')
IS_EXEMPT=$(echo "${STATUS_RES}" | jq -r '.is_exempt_from_platform_fee')

if [ "${IS_ACTIVE}" = "false" ] && [ "${IS_EXEMPT}" = "false" ]; then
  pass "Initial subscription status is inactive and not fee-exempt"
else
  fail "Unexpected initial subscription status: ${STATUS_RES}"
fi

# 4. Acceptance verification: Storage write operations rejected without active subscription
echo "[Test 4] Verifying storage rejection for unsubscribed customer..."
MKDIR_RES=$(curl -s -X POST "${BASE_URL}/api/v1/directories" \
  -H "${CUST_AUTH}" \
  -H "Content-Type: application/json" \
  -d '{"name":"ShouldBeBlocked"}')

if echo "${MKDIR_RES}" | grep -q 'SUBSCRIPTION_REQUIRED'; then
  pass "Directory creation blocked with SUBSCRIPTION_REQUIRED (HTTP 403)"
else
  fail "Directory creation was not blocked: ${MKDIR_RES}"
fi

# 5. Customer submits subscription request
echo "[Test 5] Customer requests 3.00€/month subscription..."
REQ_RES=$(curl -s -X POST "${BASE_URL}/api/v1/subscriptions/request" \
  -H "${CUST_AUTH}" \
  -H "Content-Type: application/json" \
  -d '{"notes":"Personal 50GB storage upgrade"}')

REQ_ID=$(echo "${REQ_RES}" | jq -r '.request.id // empty')
REQ_STATUS=$(echo "${REQ_RES}" | jq -r '.request.status // empty')

if [ -n "${REQ_ID}" ] && [ "${REQ_STATUS}" = "REQUESTED" ]; then
  pass "Subscription request created (ID: ${REQ_ID}, Status: ${REQ_STATUS})"
else
  fail "Subscription request failed: ${REQ_RES}"
fi

# 6. Duplicate request rejection (409 Conflict)
echo "[Test 6] Submitting duplicate request while pending..."
DUP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X POST "${BASE_URL}/api/v1/subscriptions/request" \
  -H "${CUST_AUTH}" \
  -H "Content-Type: application/json" \
  -d '{"notes":"Duplicate request"}')

if [ "${DUP_STATUS}" -eq 409 ]; then
  pass "Duplicate pending request rejected with HTTP 409"
else
  fail "Expected HTTP 409, got ${DUP_STATUS}"
fi

# 7. Customer cannot access partner review endpoints (403 Forbidden)
echo "[Test 7] Customer role blocked from partner requests..."
FORBID_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X GET "${BASE_URL}/api/v1/partner/subscription-requests" -H "${CUST_AUTH}")
if [ "${FORBID_STATUS}" -eq 403 ]; then
  pass "Customer blocked with HTTP 403"
else
  fail "Expected HTTP 403, got ${FORBID_STATUS}"
fi

# 8. Partner login
echo "[Test 8] Partner logs in..."
PARTNER_LOGIN=$(curl -s -X POST "${BASE_URL}/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"partner","password":"PartnerPass123!"}')

PART_TOKEN=$(echo "${PARTNER_LOGIN}" | jq -r '.data.token // empty')
PART_AUTH="Authorization: Bearer ${PART_TOKEN}"

if [ -n "${PART_TOKEN}" ]; then
  pass "Partner authenticated"
else
  fail "Partner authentication failed: ${PARTNER_LOGIN}"
  exit 1
fi

# 9. Partner lists requests
echo "[Test 9] Partner lists pending subscription requests..."
LIST_RES=$(curl -s -X GET "${BASE_URL}/api/v1/partner/subscription-requests?status=REQUESTED" -H "${PART_AUTH}")
FOUND_REQ=$(echo "${LIST_RES}" | jq -r --arg rid "${REQ_ID}" '.requests[] | select(.id == $rid) | .id')

if [ "${FOUND_REQ}" = "${REQ_ID}" ]; then
  pass "Partner found customer request ${REQ_ID}"
else
  fail "Request ${REQ_ID} not in partner listing: ${LIST_RES}"
fi

# Record partner debt before approval
DEBT_BEFORE=$(docker compose exec -T webapp php -r "require 'vendor/autoload.php'; \App\Core\Config::load(); \$db = \App\Core\Database::getConnection(); echo \$db->query('SELECT p.debt_cents FROM partners p JOIN users u ON p.user_id = u.id WHERE u.username = \"partner\"')->fetchColumn();")

# 10. Partner approves subscription
echo "[Test 10] Partner approves subscription request..."
APPROVE_RES=$(curl -s -X POST "${BASE_URL}/api/v1/partner/subscription-requests/${REQ_ID}/approve" -H "${PART_AUTH}")
SUB_ID=$(echo "${APPROVE_RES}" | jq -r '.data.subscription.id // empty')
SUB_STATUS=$(echo "${APPROVE_RES}" | jq -r '.data.subscription.status // empty')
FEE_CHARGED=$(echo "${APPROVE_RES}" | jq -r '.data.fee_charged_cents // 0')

if [ -n "${SUB_ID}" ] && [ "${SUB_STATUS}" = "ACTIVE" ] && [ "${FEE_CHARGED}" -eq 300 ]; then
  pass "Subscription approved: ${SUB_ID} (Active, 3.00€ billed)"
else
  fail "Approval failed: ${APPROVE_RES}"
fi

# 11. Verify partner debt increment and billing entry in database
echo "[Test 11] Verifying partner debt increment & billing entry..."
DEBT_AFTER=$(docker compose exec -T webapp php -r "require 'vendor/autoload.php'; \App\Core\Config::load(); \$db = \App\Core\Database::getConnection(); echo \$db->query('SELECT p.debt_cents FROM partners p JOIN users u ON p.user_id = u.id WHERE u.username = \"partner\"')->fetchColumn();")
EXPECTED_DEBT=$((DEBT_BEFORE + 300))

if [ "${DEBT_AFTER}" -eq "${EXPECTED_DEBT}" ]; then
  pass "Partner debt correctly increased by 300 cents (from ${DEBT_BEFORE} to ${DEBT_AFTER})"
else
  fail "Partner debt mismatch: expected ${EXPECTED_DEBT}, got ${DEBT_AFTER}"
fi

BILLING_COUNT=$(docker compose exec -T webapp php -r "require 'vendor/autoload.php'; \App\Core\Config::load(); \$db = \App\Core\Database::getConnection(); echo \$db->query('SELECT count(*) FROM partner_billing_entries WHERE reference_id = \"${SUB_ID}\" AND amount_cents = 300 AND operation_type = \"SUBSCRIPTION_RENEWAL\"')->fetchColumn();")
if [ "${BILLING_COUNT}" -ge 1 ]; then
  pass "Audit-compliant partner billing entry recorded for subscription activation"
else
  fail "Billing entry not found for subscription ${SUB_ID}"
fi

# 12. Verify customer status updated to ACTIVE with fee exemption
echo "[Test 12] Customer subscription status verification..."
CUST_SUB_RES=$(curl -s -X GET "${BASE_URL}/api/v1/subscriptions/current" -H "${CUST_AUTH}")
CUST_ACTIVE=$(echo "${CUST_SUB_RES}" | jq -r '.is_active')
CUST_EXEMPT=$(echo "${CUST_SUB_RES}" | jq -r '.is_exempt_from_platform_fee')
CUST_QUOTA=$(echo "${CUST_SUB_RES}" | jq -r '.quota_bytes')

if [ "${CUST_ACTIVE}" = "true" ] && [ "${CUST_EXEMPT}" = "true" ] && [ "${CUST_QUOTA}" -eq 53687091200 ]; then
  pass "Customer is active subscriber (50 GiB quota, fee exempt)"
else
  fail "Customer status not active: ${CUST_SUB_RES}"
fi

# 13. Acceptance check: Customer can now use storage
echo "[Test 13] Acceptance test: Active subscriber creates storage directory..."
ALLOW_MKDIR=$(curl -s -X POST "${BASE_URL}/api/v1/directories" \
  -H "${CUST_AUTH}" \
  -H "Content-Type: application/json" \
  -d '{"name":"SubscriberVault"}')

VAULT_ID=$(echo "${ALLOW_MKDIR}" | jq -r '.data.id // empty')
if [ -n "${VAULT_ID}" ]; then
  pass "Active subscriber successfully created folder (ID: ${VAULT_ID})"
else
  fail "Directory creation failed for active subscriber: ${ALLOW_MKDIR}"
fi

# 14. Partner renews subscription
echo "[Test 14] Partner renews subscription..."
RENEW_RES=$(curl -s -X POST "${BASE_URL}/api/v1/partner/subscriptions/${SUB_ID}/renew" -H "${PART_AUTH}")
RENEW_STATUS=$(echo "${RENEW_RES}" | jq -r '.subscription.status // empty')

if [ "${RENEW_STATUS}" = "ACTIVE" ]; then
  pass "Subscription renewed successfully"
else
  fail "Subscription renewal failed: ${RENEW_RES}"
fi

DEBT_AFTER_RENEW=$(docker compose exec -T webapp php -r "require 'vendor/autoload.php'; \App\Core\Config::load(); \$db = \App\Core\Database::getConnection(); echo \$db->query('SELECT p.debt_cents FROM partners p JOIN users u ON p.user_id = u.id WHERE u.username = \"partner\"')->fetchColumn();")
EXPECTED_DEBT_RENEW=$((EXPECTED_DEBT + 300))
if [ "${DEBT_AFTER_RENEW}" -eq "${EXPECTED_DEBT_RENEW}" ]; then
  pass "Partner debt correctly incremented another 300 cents on renewal (${DEBT_AFTER_RENEW} cents total)"
else
  fail "Partner debt mismatch after renewal: expected ${EXPECTED_DEBT_RENEW}, got ${DEBT_AFTER_RENEW}"
fi

# 15. Rejection workflow test
echo "[Test 15] Testing rejection workflow with Customer 2..."
CUST2_USER="sub2_cust_${RAND_SUFFIX}"
CUST2_EMAIL="sub2_cust_${RAND_SUFFIX}@test.local"
REG2_RES=$(curl -s -X POST "${BASE_URL}/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"${CUST2_USER}\",\"email\":\"${CUST2_EMAIL}\",\"password\":\"${CUST_PASS}\"}")

CUST2_TOKEN=$(echo "${REG2_RES}" | jq -r '.data.token // empty')
CUST2_AUTH="Authorization: Bearer ${CUST2_TOKEN}"

REQ2_RES=$(curl -s -X POST "${BASE_URL}/api/v1/subscriptions/request" \
  -H "${CUST2_AUTH}" \
  -H "Content-Type: application/json" \
  -d '{"notes":"Request destined for rejection"}')
REQ2_ID=$(echo "${REQ2_RES}" | jq -r '.request.id // empty')

REJECT_RES=$(curl -s -X POST "${BASE_URL}/api/v1/partner/subscription-requests/${REQ2_ID}/reject" \
  -H "${PART_AUTH}" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Merchant review policy rejection"}')

REJECT_STATUS=$(echo "${REJECT_RES}" | jq -r '.request.status // empty')
if [ "${REJECT_STATUS}" = "REJECTED" ]; then
  pass "Subscription request successfully rejected"
else
  fail "Rejection failed: ${REJECT_RES}"
fi

# 16. Customer cancellation workflow
echo "[Test 16] Customer cancels subscription..."
CANCEL_RES=$(curl -s -X POST "${BASE_URL}/api/v1/subscriptions/cancel" -H "${CUST_AUTH}")
CANCEL_STATUS=$(echo "${CANCEL_RES}" | jq -r '.subscription.status // empty')

if [ "${CANCEL_STATUS}" = "CANCELLED" ]; then
  pass "Subscription cancelled successfully"
else
  fail "Cancellation failed: ${CANCEL_RES}"
fi

echo "========================================================"
echo "    Phase 5 Subscriptions Results: ${PASSED} passed, ${FAILED} failed"
echo "========================================================"

if [ "${FAILED}" -gt 0 ]; then
  exit 1
fi
