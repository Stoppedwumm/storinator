#!/usr/bin/env bash
set -euo pipefail

# Automated Health & Architecture Verification Test Suite (Phase 1)
# Verifies container health, reverse-proxy routing, database integrity, and network isolation

PORT="${PORT:-8080}"
BASE_URL="http://localhost:${PORT}"
PASS_COUNT=0
FAIL_COUNT=0

assert_test() {
    local name="$1"
    local result="$2"
    if [ "$result" -eq 0 ]; then
        echo "  ✔ PASS: $name"
        PASS_COUNT=$((PASS_COUNT + 1))
    else
        echo "  ✖ FAIL: $name"
        FAIL_COUNT=$((FAIL_COUNT + 1))
    fi
}

echo "===================================================="
echo "Platform Automated Health Verification Suite"
echo "Target Base URL: ${BASE_URL}"
echo "===================================================="

# 1. Test Webapp Index
echo "[1] Testing Webapp HTML Endpoint (/) ..."
STATUS_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/" || echo "000")
if [ "$STATUS_CODE" -eq 200 ]; then
    assert_test "Webapp returns HTTP 200" 0
else
    echo "  Status was: $STATUS_CODE"
    assert_test "Webapp returns HTTP 200" 1
fi

# 2. Test Webapp Health
echo "[2] Testing Webapp Health Endpoint (/health) ..."
WEBAPP_HEALTH=$(curl -s "${BASE_URL}/health" || echo "")
if echo "$WEBAPP_HEALTH" | grep -q '"status":"healthy"'; then
    assert_test "Webapp health endpoint reports healthy" 0
else
    echo "  Response was: $WEBAPP_HEALTH"
    assert_test "Webapp health endpoint reports healthy" 1
fi

# 3. Test Backend Health via Webapp Reverse Proxy (/api/health)
echo "[3] Testing Backend Health via Reverse Proxy (/api/health) ..."
BACKEND_HEALTH=$(curl -s "${BASE_URL}/api/health" || echo "")
if echo "$BACKEND_HEALTH" | grep -q '"success":true'; then
    assert_test "Backend API responds with success:true" 0
else
    echo "  Response was: $BACKEND_HEALTH"
    assert_test "Backend API responds with success:true" 1
fi

# 4. Verify Backend Database Status
echo "[4] Verifying Backend SQLite Database status ..."
if echo "$BACKEND_HEALTH" | grep -q '"status":"connected"'; then
    assert_test "Backend SQLite connection is healthy" 0
else
    assert_test "Backend SQLite connection is healthy" 1
fi

# 5. Verify Backend to BigStore Internal Connectivity
echo "[5] Verifying Backend -> BigStore Internal Network Connectivity ..."
if echo "$BACKEND_HEALTH" | grep -q '"service":"bigstore"'; then
    assert_test "Backend successfully communicates with BigStore across internal network" 0
else
    assert_test "Backend successfully communicates with BigStore across internal network" 1
fi

# 6. Verify Entry Code API via Reverse Proxy
echo "[6] Testing Secret Entry Code Endpoint (/api/v1/entry/verify) ..."
ENTRY_RES=$(curl -s -X POST "${BASE_URL}/api/v1/entry/verify" \
    -H "Content-Type: application/json" \
    -d '{"code":"anticipation2026"}' || echo "")
if echo "$ENTRY_RES" | grep -q '"entry_unlocked":true'; then
    assert_test "Entry code verification succeeds with valid token" 0
else
    echo "  Response was: $ENTRY_RES"
    assert_test "Entry code verification succeeds with valid token" 1
fi

# 7. Verify Network Isolation (BigStore port 8080 must NOT be exposed directly on host)
echo "[7] Verifying Network Isolation (BigStore must be internal-only) ..."
BIGSTORE_PORTS=$(docker inspect -f '{{range $p, $conf := .NetworkSettings.Ports}}{{with $conf}}{{$p}} -> {{(index . 0).HostPort}}{{end}}{{end}}' platform-bigstore || echo "")
PUBLIC_LEAK_CHECK=$(curl -s "${BASE_URL}/internal/health" || echo "")

if [ -z "$BIGSTORE_PORTS" ] && ! echo "$PUBLIC_LEAK_CHECK" | grep -q '"service":"bigstore"'; then
    assert_test "BigStore has zero host port mappings and is strictly isolated from public reverse-proxy" 0
else
    assert_test "BigStore has zero host port mappings and is strictly isolated from public reverse-proxy" 1
fi

# 8. Verify BigStore Service Token Authentication inside Container Network
echo "[8] Verifying BigStore Service Token Security inside container ..."
BIGSTORE_SEC_TEST=$(docker exec platform-webapp php -r '
    $ch = curl_init("http://bigstore:8080/internal/health");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo $code;
' || echo "500")
if [ "$BIGSTORE_SEC_TEST" -eq 401 ]; then
    assert_test "BigStore rejects requests lacking X-Internal-Service-Token (HTTP 401)" 0
else
    echo "  HTTP code was: $BIGSTORE_SEC_TEST"
    assert_test "BigStore rejects requests lacking X-Internal-Service-Token (HTTP 401)" 1
fi

echo "===================================================="
echo "Health Suite Summary: ${PASS_COUNT} Passed, ${FAIL_COUNT} Failed."
echo "===================================================="

if [ "$FAIL_COUNT" -gt 0 ]; then
    exit 1
fi
exit 0
