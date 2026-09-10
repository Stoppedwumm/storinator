#!/bin/bash
# ==============================================================================
# Platform Phase 2 Authentication & Authorization Test Suite
# Tests:
#   1. Admin Login & Token generation
#   2. Partner Login & Token generation
#   3. Customer Login & Token generation
#   4. Unauthenticated Access Protection (401)
#   5. Role-Gated Access Control (Customer -> Customer 200, Partner 403, Admin 403)
#   6. Role-Gated Access Control (Partner -> Customer 200, Partner 200, Admin 403)
#   7. Role-Gated Access Control (Admin -> Customer 200, Partner 200, Admin 200)
#   8. Session Revocation / Logout (Token unusable after logout)
#   9. Customer Self-Registration & Duplicate Prevention (409)
#  10. Brute-Force Rate Limiting (5 failures allowed, 6th returns 429)
# ==============================================================================

set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080/api}"
PASSED=0
FAILED=0

# Ensure clean slate for test runner IP
docker compose exec -T backend php -r "
    require 'vendor/autoload.php';
    use App\Core\Database;
    Database::getConnection()->exec('DELETE FROM login_attempts');
" >/dev/null 2>&1 || true

red() { echo -e "\033[31m$1\033[0m"; }
green() { echo -e "\033[32m$1\033[0m"; }
cyan() { echo -e "\033[36m$1\033[0m"; }

test_step() {
  local num="$1"
  local desc="$2"
  echo -n "[Test $num] $desc ... "
}

assert_eq() {
  local actual="$1"
  local expected="$2"
  local test_name="$3"
  if [ "$actual" == "$expected" ]; then
    green "PASSED"
    PASSED=$((PASSED + 1))
  else
    red "FAILED (Expected: $expected, got: $actual)"
    FAILED=$((FAILED + 1))
  fi
}

cyan "========================================================"
cyan "    Running Phase 2 Authentication & Role Test Suite    "
cyan "========================================================"

# Test 1: Admin Login
test_step "1" "Admin login with Argon2id credentials"
ADMIN_RESP=$(curl -s -X POST "$BASE_URL/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"admin","password":"AdminPass123!"}')
ADMIN_TOKEN=$(echo "$ADMIN_RESP" | jq -r '.data.token // empty')
ADMIN_ROLE=$(echo "$ADMIN_RESP" | jq -r '.data.user.roles[0] // empty')
if [ -n "$ADMIN_TOKEN" ] && [ "$ADMIN_ROLE" == "ADMIN" ]; then
  green "PASSED"
  PASSED=$((PASSED + 1))
else
  red "FAILED ($ADMIN_RESP)"
  FAILED=$((FAILED + 1))
fi

# Test 2: Partner Login
test_step "2" "Partner login with Argon2id credentials"
PARTNER_RESP=$(curl -s -X POST "$BASE_URL/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"partner","password":"PartnerPass123!"}')
PARTNER_TOKEN=$(echo "$PARTNER_RESP" | jq -r '.data.token // empty')
PARTNER_ROLES=$(echo "$PARTNER_RESP" | jq -r '.data.user.roles | join(",")')
if [ -n "$PARTNER_TOKEN" ] && [[ "$PARTNER_ROLES" == *"PARTNER"* ]]; then
  green "PASSED"
  PASSED=$((PASSED + 1))
else
  red "FAILED ($PARTNER_RESP)"
  FAILED=$((FAILED + 1))
fi

# Test 3: Customer Login
test_step "3" "Customer login with Argon2id credentials"
CUSTOMER_RESP=$(curl -s -X POST "$BASE_URL/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"customer","password":"CustomerPass123!"}')
CUSTOMER_TOKEN=$(echo "$CUSTOMER_RESP" | jq -r '.data.token // empty')
CUSTOMER_ROLE=$(echo "$CUSTOMER_RESP" | jq -r '.data.user.roles[0] // empty')
if [ -n "$CUSTOMER_TOKEN" ] && [ "$CUSTOMER_ROLE" == "CUSTOMER" ]; then
  green "PASSED"
  PASSED=$((PASSED + 1))
else
  red "FAILED ($CUSTOMER_RESP)"
  FAILED=$((FAILED + 1))
fi

# Test 4: Unauthenticated access returns 401
test_step "4" "Unauthenticated request receives HTTP 401"
UNAUTH_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/v1/customer/ping")
assert_eq "$UNAUTH_CODE" "401" "Unauthenticated access"

# Test 5: Customer access boundaries (Customer: 200, Partner: 403, Admin: 403)
test_step "5" "Customer access boundaries (Cust: 200, Part: 403, Admin: 403)"
CUST_TO_CUST=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $CUSTOMER_TOKEN" "$BASE_URL/v1/customer/ping")
CUST_TO_PART=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $CUSTOMER_TOKEN" "$BASE_URL/v1/partner/ping")
CUST_TO_ADM=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $CUSTOMER_TOKEN" "$BASE_URL/v1/admin/ping")
if [ "$CUST_TO_CUST" == "200" ] && [ "$CUST_TO_PART" == "403" ] && [ "$CUST_TO_ADM" == "403" ]; then
  green "PASSED"
  PASSED=$((PASSED + 1))
else
  red "FAILED (Cust: $CUST_TO_CUST, Part: $CUST_TO_PART, Admin: $CUST_TO_ADM)"
  FAILED=$((FAILED + 1))
fi

# Test 6: Partner access boundaries (Customer: 200, Partner: 200, Admin: 403)
test_step "6" "Partner access boundaries (Cust: 200, Part: 200, Admin: 403)"
PART_TO_CUST=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $PARTNER_TOKEN" "$BASE_URL/v1/customer/ping")
PART_TO_PART=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $PARTNER_TOKEN" "$BASE_URL/v1/partner/ping")
PART_TO_ADM=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $PARTNER_TOKEN" "$BASE_URL/v1/admin/ping")
if [ "$PART_TO_CUST" == "200" ] && [ "$PART_TO_PART" == "200" ] && [ "$PART_TO_ADM" == "403" ]; then
  green "PASSED"
  PASSED=$((PASSED + 1))
else
  red "FAILED (Cust: $PART_TO_CUST, Part: $PART_TO_PART, Admin: $PART_TO_ADM)"
  FAILED=$((FAILED + 1))
fi

# Test 7: Admin access boundaries (Customer: 200, Partner: 200, Admin: 200)
test_step "7" "Admin access boundaries (Cust: 200, Part: 200, Admin: 200)"
ADM_TO_CUST=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $ADMIN_TOKEN" "$BASE_URL/v1/customer/ping")
ADM_TO_PART=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $ADMIN_TOKEN" "$BASE_URL/v1/partner/ping")
ADM_TO_ADM=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $ADMIN_TOKEN" "$BASE_URL/v1/admin/ping")
if [ "$ADM_TO_CUST" == "200" ] && [ "$ADM_TO_PART" == "200" ] && [ "$ADM_TO_ADM" == "200" ]; then
  green "PASSED"
  PASSED=$((PASSED + 1))
else
  red "FAILED (Cust: $ADM_TO_CUST, Part: $ADM_TO_PART, Admin: $ADM_TO_ADM)"
  FAILED=$((FAILED + 1))
fi

# Test 8: Logout revokes session immediately
test_step "8" "Logout immediately revokes session token"
TEMP_LOGOUT_RESP=$(curl -s -X POST "$BASE_URL/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"customer","password":"CustomerPass123!"}')
TEMP_TOKEN=$(echo "$TEMP_LOGOUT_RESP" | jq -r '.data.token')
curl -s -X POST "$BASE_URL/v1/auth/logout" -H "Authorization: Bearer $TEMP_TOKEN" > /dev/null
AFTER_LOGOUT_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $TEMP_TOKEN" "$BASE_URL/v1/customer/ping")
assert_eq "$AFTER_LOGOUT_CODE" "401" "Revoked session rejected"

# Test 9: Self-registration & duplicate email prevention (409)
test_step "9" "Self-registration & duplicate registration prevention (409)"
RAND_NAME="testuser_$(date +%s)"
REG_RESP=$(curl -s -X POST "$BASE_URL/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"$RAND_NAME\",\"email\":\"$RAND_NAME@example.com\",\"password\":\"TestPass123!\"}")
REG_TOKEN=$(echo "$REG_RESP" | jq -r '.data.token // empty')
DUP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"$RAND_NAME\",\"email\":\"$RAND_NAME@example.com\",\"password\":\"TestPass123!\"}")
if [ -n "$REG_TOKEN" ] && [ "$DUP_CODE" == "409" ]; then
  green "PASSED"
  PASSED=$((PASSED + 1))
else
  red "FAILED (Reg token: $REG_TOKEN, Dup code: $DUP_CODE)"
  FAILED=$((FAILED + 1))
fi

# Test 10: Rate limiting on repeated failed attempts (429)
test_step "10" "Brute force rate limiter blocks 6th attempt (429)"
TARGET_USER="ratelimit_target_$(date +%s)"
# 5 failed attempts
for i in {1..5}; do
  curl -s -X POST "$BASE_URL/v1/auth/login" \
    -H "Content-Type: application/json" \
    -d "{\"identifier\":\"$TARGET_USER\",\"password\":\"wrong\"}" > /dev/null
done
# 6th attempt
BLOCKED_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"identifier\":\"$TARGET_USER\",\"password\":\"wrong\"}")
assert_eq "$BLOCKED_CODE" "429" "Rate limit 429"
docker compose exec -T backend php -r "
    require 'vendor/autoload.php';
    use App\Core\Database;
    Database::getConnection()->exec('DELETE FROM login_attempts');
" >/dev/null 2>&1 || true

cyan "========================================================"
cyan "    Phase 2 Authentication Results: $PASSED passed, $FAILED failed"
cyan "========================================================"

if [ "$FAILED" -gt 0 ]; then
  exit 1
fi
exit 0
