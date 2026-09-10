#!/usr/bin/env bash
set -e

# ==============================================================================
# Phase 3 Verification Suite — Landing Page & Secret Teaser Access
# Tests:
# 1. Landing page accessibility & markup
# 2. Asset bundle loading (CSS & JS)
# 3. Missing code parameter validation (400)
# 4. Failed search query obscures hidden console (404 NO_RESULTS)
# 5. Secret entry code unlocks console and issues HttpOnly cookie (200)
# 6. Alias route POST /api/entry-code
# 7. Alias route POST /api/entry/verify
# 8. GET /api/v1/entry/status (unauthenticated vs cookie authenticated)
# 9. Sliding-window brute force protection (429 TOO_MANY_ATTEMPTS)
# 10. Audit logging verification in SQLite
# ==============================================================================

BASE_URL="http://localhost:8080"
PASS=0
FAIL=0

COLOR_GREEN="\033[0;32m"
COLOR_RED="\033[0;31m"
COLOR_CYAN="\033[0;36m"
COLOR_RESET="\033[0m"

log_info() {
    echo -e "${COLOR_CYAN}[INFO]${COLOR_RESET} $1"
}

assert_true() {
    local desc="$1"
    local condition="$2"
    if [ "$condition" = "true" ]; then
        echo -e "${COLOR_GREEN}[PASS]${COLOR_RESET} $desc"
        PASS=$((PASS + 1))
    else
        echo -e "${COLOR_RED}[FAIL]${COLOR_RESET} $desc"
        FAIL=$((FAIL + 1))
    fi
}

echo "===================================================================="
echo " Starting Phase 3 Landing Page & Teaser Verification Suite"
echo "===================================================================="

# Reset any prior rate-limiting for entry code tests
docker compose exec -T webapp php -r "
    require 'vendor/autoload.php';
    \App\Core\Config::load();
    \App\Core\Database::getConnection()->exec(\"DELETE FROM login_attempts WHERE identifier = 'entry_code'\");
" >/dev/null 2>&1 || true

# Test 1: Public landing page HTML
log_info "Testing Landing Page delivery..."
HTML_BODY=$(curl -s "$BASE_URL/")
if echo "$HTML_BODY" | grep -q "AETHER Platform"; then
    assert_true "Landing page serves correct corporate title and HTML shell" "true"
else
    assert_true "Landing page serves correct corporate title and HTML shell" "false"
fi

# Test 2: Asset bundle loading
log_info "Testing frontend assets..."
CSS_PATH=$(echo "$HTML_BODY" | grep -oE 'href="?[^ ">]+\.css"?' | head -n1 | tr -d '"' | sed 's/href=//')
JS_PATH=$(echo "$HTML_BODY" | grep -oE 'src="?[^ ">]+\.js"?' | head -n1 | tr -d '"' | sed 's/src=//')

CSS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL$CSS_PATH")
JS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL$JS_PATH")

assert_true "Compiled CSS stylesheet ($CSS_PATH) returns HTTP 200" "$([ "$CSS_STATUS" = "200" ] && echo true || echo false)"
assert_true "Compiled JS application bundle ($JS_PATH) returns HTTP 200" "$([ "$JS_STATUS" = "200" ] && echo true || echo false)"

# Test 3: Missing parameter validation
log_info "Testing entry code parameter validation..."
MISSING_RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/v1/entry/verify" \
    -H "Content-Type: application/json" \
    -d '{}')
STATUS_CODE=$(echo "$MISSING_RESP" | tail -n1)
BODY=$(echo "$MISSING_RESP" | sed '$d')

assert_true "POST without code parameter returns HTTP 400" "$([ "$STATUS_CODE" = "400" ] && echo true || echo false)"
assert_true "Error response indicates invalid or missing input" "$(echo "$BODY" | grep -qE "INVALID_INPUT|MISSING_PARAM" && echo true || echo false)"

# Test 4: Obscured response for non-matching queries
log_info "Testing query obscurity for unmatched search term..."
UNMATCHED_RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/v1/entry/verify" \
    -H "Content-Type: application/json" \
    -d '{"code":"nonexistent_topic"}')
STATUS_CODE=$(echo "$UNMATCHED_RESP" | tail -n1)
BODY=$(echo "$UNMATCHED_RESP" | sed '$d')

assert_true "Unmatched query returns HTTP 404" "$([ "$STATUS_CODE" = "404" ] && echo true || echo false)"
assert_true "Unmatched query obscures secret login and returns NO_RESULTS" "$(echo "$BODY" | grep -q "NO_RESULTS" && echo true || echo false)"

# Test 5: Secret access code verification and cookie issuance
log_info "Testing secret entry verification (code: anticipation2026)..."
COOKIE_JAR=$(mktemp)
VERIFY_HEADER=$(curl -s -i -c "$COOKIE_JAR" -X POST "$BASE_URL/api/v1/entry/verify" \
    -H "Content-Type: application/json" \
    -d '{"code":"anticipation2026"}')

assert_true "Valid entry code returns HTTP 200" "$(echo "$VERIFY_HEADER" | grep -q "200 OK" && echo true || echo false)"
assert_true "Response issues HttpOnly platform_entry cookie" "$(echo "$VERIFY_HEADER" | grep -iq "Set-Cookie: platform_entry" && echo true || echo false)"
assert_true "Response returns redirect: #/login" "$(echo "$VERIFY_HEADER" | grep -q "#/login" && echo true || echo false)"

# Test 6: Route alias /api/entry-code
log_info "Testing route alias POST /api/entry-code..."
ALIAS_RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/entry-code" \
    -H "Content-Type: application/json" \
    -d '{"code":"anticipation2026"}')
STATUS_CODE=$(echo "$ALIAS_RESP" | tail -n1)
assert_true "Alias /api/entry-code returns HTTP 200" "$([ "$STATUS_CODE" = "200" ] && echo true || echo false)"

# Test 7: Route alias POST /api/entry/verify
log_info "Testing route alias POST /api/entry/verify..."
ALIAS2_RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/entry/verify" \
    -H "Content-Type: application/json" \
    -d '{"code":"anticipation2026"}')
STATUS_CODE=$(echo "$ALIAS2_RESP" | tail -n1)
assert_true "Alias /api/entry/verify returns HTTP 200" "$([ "$STATUS_CODE" = "200" ] && echo true || echo false)"

# Test 8: Status endpoint without cookie
log_info "Testing GET /api/v1/entry/status without cookie..."
STATUS_ANON=$(curl -s "$BASE_URL/api/v1/entry/status")
assert_true "Status without cookie returns unlocked: false" "$(echo "$STATUS_ANON" | grep -q '"unlocked":false' && echo true || echo false)"

# Test 9: Status endpoint with cookie
log_info "Testing GET /api/v1/entry/status with cookie..."
STATUS_AUTH=$(curl -s -b "$COOKIE_JAR" "$BASE_URL/api/v1/entry/status")
assert_true "Status with platform_entry cookie returns unlocked: true" "$(echo "$STATUS_AUTH" | grep -q '"unlocked":true' && echo true || echo false)"
rm -f "$COOKIE_JAR"

# Test 10: Rate limiting brute force defense
log_info "Testing brute-force rate limiter on entry endpoint (5 attempts allowed, then 429)..."
# Clear attempts first
docker compose exec -T webapp php -r "
    require 'vendor/autoload.php';
    (new App\Services\RateLimiter())->clearEntryAttempts('127.0.0.1');
    (new App\Services\RateLimiter())->clearEntryAttempts('172.19.0.1');
" >/dev/null 2>&1 || true

for i in {1..5}; do
    curl -s -o /dev/null -X POST "$BASE_URL/api/v1/entry/verify" \
        -H "Content-Type: application/json" \
        -d "{\"code\":\"attempt_$i\"}"
done

RATE_LIMITED_RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/v1/entry/verify" \
    -H "Content-Type: application/json" \
    -d '{"code":"excess_attempt"}')
STATUS_CODE=$(echo "$RATE_LIMITED_RESP" | tail -n1)
BODY=$(echo "$RATE_LIMITED_RESP" | sed '$d')

assert_true "Excess search attempts return HTTP 429" "$([ "$STATUS_CODE" = "429" ] && echo true || echo false)"
assert_true "Response error code is TOO_MANY_ATTEMPTS" "$(echo "$BODY" | grep -q "TOO_MANY_ATTEMPTS" && echo true || echo false)"

# Test 11: Audit log record in database
log_info "Testing database audit log for entry verifications..."
AUDIT_COUNT=$(docker compose exec -T webapp php -r "
    require 'vendor/autoload.php';
    use App\Core\Database;
    \$stmt = Database::getConnection()->query(\"SELECT count(*) FROM audit_logs WHERE action = 'ENTRY_CODE_VERIFIED'\");
    echo \$stmt->fetchColumn();
")
assert_true "Audit logs contain verified entry actions (count: $AUDIT_COUNT)" "$([ "$AUDIT_COUNT" -gt 0 ] && echo true || echo false)"

# Cleanup test rate limits
docker compose exec -T webapp php -r "
    require 'vendor/autoload.php';
    \App\Core\Config::load();
    \App\Core\Database::getConnection()->exec(\"DELETE FROM login_attempts WHERE identifier = 'entry_code'\");
" >/dev/null 2>&1 || true

echo "===================================================================="
echo -e " Phase 3 Test Results: ${COLOR_GREEN}$PASS Passed${COLOR_RESET}, ${COLOR_RED}$FAIL Failed${COLOR_RESET}"
echo "===================================================================="

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
