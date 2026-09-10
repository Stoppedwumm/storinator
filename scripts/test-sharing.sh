#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Phase 8: File Sharing Test Suite
# Tests:
# 1. Unauthenticated checks on /api/v1/shares
# 2. Registration and file upload
# 3. Create public share link (tokenized URL)
# 4. Anonymous public query on unprotected share (view counter)
# 5. Download file content through backend proxy (download counter)
# 6. Password-protected share creation, unlock token, and gated download
# 7. Download-disabled share enforcement (HTTP 403)
# 8. Max downloads limit enforcement (HTTP 410)
# 9. Expiration enforcement (HTTP 410)
# 10. Owner revocation workflow (HTTP 410 on public access)
# 11. Strict BigStore path concealment & cross-user access controls
# ==============================================================================

BASE_URL="http://localhost:8080"
PASS_COUNT=0
FAIL_COUNT=0

pass() {
  echo -e "  \033[32m✔ PASS\033[0m: $1"
  PASS_COUNT=$((PASS_COUNT + 1))
}

fail() {
  echo -e "  \033[31m✖ FAIL\033[0m: $1"
  FAIL_COUNT=$((FAIL_COUNT + 1))
}

RAND_SUFFIX="$(date +%s)_$RANDOM"
TIMESTAMP="$(date +%s)"

echo "========================================================"
echo "      Running Phase 8 File Sharing Test Suite           "
echo "========================================================"

# ------------------------------------------------------------------------------
# Test 1: Unauthenticated request to /api/v1/shares receives 401
# ------------------------------------------------------------------------------
echo "[Test 1] Verifying unauthenticated requests receive HTTP 401..."
CODE1=$(curl -s -o /dev/null -w "%{http_code}" -X POST "${BASE_URL}/api/v1/shares" \
  -H "Content-Type: application/json" -d '{"file_id":"test"}')
if [ "$CODE1" -eq 401 ]; then
  pass "POST /api/v1/shares rejected with UNAUTHORIZED (401)"
else
  fail "Expected 401, got $CODE1"
fi

CODE2=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/shares")
if [ "$CODE2" -eq 401 ]; then
  pass "GET /api/v1/shares rejected with UNAUTHORIZED (401)"
else
  fail "Expected 401, got $CODE2"
fi

# ------------------------------------------------------------------------------
# Test 2: Register user, partner approve subscription, upload test file
# ------------------------------------------------------------------------------
echo "[Test 2] Setting up customer and uploading a test file..."
CUST_USER="share_owner_${RAND_SUFFIX}"
CUST_PASS="SecureOwnerPass123!"

REGISTER_RES=$(curl -s -X POST "${BASE_URL}/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"${CUST_USER}\",\"email\":\"${CUST_USER}@example.com\",\"password\":\"${CUST_PASS}\"}")

CUST_TOKEN=$(echo "$REGISTER_RES" | jq -r '.data.token // .data.session_token // empty')
CUST_ID=$(echo "$REGISTER_RES" | jq -r '.data.user.id // empty')

if [ -n "$CUST_TOKEN" ]; then
  pass "Customer account created (${CUST_USER})"
else
  fail "Failed to register customer: $REGISTER_RES"
fi

# Customer requests subscription
SUB_REQ=$(curl -s -X POST "${BASE_URL}/api/v1/subscriptions/request" \
  -H "Authorization: Bearer ${CUST_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"notes":"Test sharing upgrade"}')
REQ_ID=$(echo "$SUB_REQ" | jq -r '.request.id // empty')

# Partner logs in and approves
PARTNER_LOGIN=$(curl -s -X POST "${BASE_URL}/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"partner","password":"PartnerPass123!"}')
PARTNER_TOKEN=$(echo "$PARTNER_LOGIN" | jq -r '.data.token // empty')

curl -s -X POST "${BASE_URL}/api/v1/partner/subscription-requests/${REQ_ID}/approve" \
  -H "Authorization: Bearer ${PARTNER_TOKEN}" > /dev/null

# Upload a file
TEST_FILE_CONTENT="AETHER PLATFORM TOP SECRET SPECIFICATION ${RAND_SUFFIX}"
TMP_FILE="/tmp/test_share_doc_${RAND_SUFFIX}.txt"
echo "$TEST_FILE_CONTENT" > "$TMP_FILE"

UPLOAD_RES=$(curl -s -X POST "${BASE_URL}/api/v1/files/upload" \
  -H "Authorization: Bearer ${CUST_TOKEN}" \
  -F "file=@${TMP_FILE}")
rm -f "$TMP_FILE"

FILE_ID=$(echo "$UPLOAD_RES" | jq -r '.data.id // empty')
FILE_NAME=$(echo "$UPLOAD_RES" | jq -r '.data.original_name // empty')

if [ -n "$FILE_ID" ]; then
  pass "File uploaded successfully (ID: ${FILE_ID}, Name: ${FILE_NAME})"
else
  fail "File upload failed: $UPLOAD_RES"
fi

# ------------------------------------------------------------------------------
# Test 3: Create public share link (unprotected)
# ------------------------------------------------------------------------------
echo "[Test 3] Creating default public share link..."
SHARE_RES=$(curl -s -X POST "${BASE_URL}/api/v1/shares" \
  -H "Authorization: Bearer ${CUST_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"file_id\":\"${FILE_ID}\"}")

SHARE_ID=$(echo "$SHARE_RES" | jq -r '.data.id // empty')
SHARE_TOKEN=$(echo "$SHARE_RES" | jq -r '.data.token // empty')
SHARE_URL=$(echo "$SHARE_RES" | jq -r '.data.share_url // empty')
HAS_PW=$(echo "$SHARE_RES" | jq -r '.data.has_password')
DL_ENABLED=$(echo "$SHARE_RES" | jq -r '.data.download_enabled')

if [ -n "$SHARE_TOKEN" ] && [ "$HAS_PW" = "false" ] && [ "$DL_ENABLED" = "true" ]; then
  pass "Share created with token ${SHARE_TOKEN} and URL ${SHARE_URL}"
else
  fail "Share creation failed: $SHARE_RES"
fi

# ------------------------------------------------------------------------------
# Test 4: Anonymous public query on unprotected share (view counter)
# ------------------------------------------------------------------------------
echo "[Test 4] Querying public share metadata as anonymous visitor..."
PUB_RES=$(curl -s "${BASE_URL}/api/v1/s/${SHARE_TOKEN}")
PUB_REQ_PW=$(echo "$PUB_RES" | jq -r '.data.requires_password')
PUB_NAME=$(echo "$PUB_RES" | jq -r '.data.resource_name')
PUB_VIEWS=$(echo "$PUB_RES" | jq -r '.data.view_count')

if [ "$PUB_REQ_PW" = "false" ] && [ "$PUB_VIEWS" -ge 1 ]; then
  pass "Public share metadata returned (Name: ${PUB_NAME}, Views: ${PUB_VIEWS})"
else
  fail "Public share query unexpected: $PUB_RES"
fi

# ------------------------------------------------------------------------------
# Test 5: Download file content through backend proxy
# ------------------------------------------------------------------------------
echo "[Test 5] Downloading shared file anonymously..."
DOWNLOADED_CONTENT=$(curl -s "${BASE_URL}/api/v1/s/${SHARE_TOKEN}/download")

if [ "$DOWNLOADED_CONTENT" = "$TEST_FILE_CONTENT" ]; then
  pass "Downloaded file content matches exact original payload"
else
  fail "Downloaded content mismatch: got '$DOWNLOADED_CONTENT'"
fi

# Test clean rewrite URL /s/{token}/download
DOWNLOADED_CLEAN=$(curl -s "${BASE_URL}/s/${SHARE_TOKEN}/download")
if [ "$DOWNLOADED_CLEAN" = "$TEST_FILE_CONTENT" ]; then
  pass "Clean URL /s/${SHARE_TOKEN}/download successfully downloaded file content"
else
  fail "Clean URL download failed"
fi

# Check download count
PUB_RES2=$(curl -s "${BASE_URL}/api/v1/s/${SHARE_TOKEN}")
PUB_DL_COUNT=$(echo "$PUB_RES2" | jq -r '.data.download_count')
if [ "$PUB_DL_COUNT" -eq 2 ]; then
  pass "Download counter incremented accurately to 2"
else
  fail "Download counter expected 2, got $PUB_DL_COUNT"
fi

# ------------------------------------------------------------------------------
# Test 6: Password-Protected Share Workflow
# ------------------------------------------------------------------------------
echo "[Test 6] Testing password-protected share workflow..."
PW_SHARE_RES=$(curl -s -X POST "${BASE_URL}/api/v1/shares" \
  -H "Authorization: Bearer ${CUST_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"file_id\":\"${FILE_ID}\",\"password\":\"SecretDoc123!\"}")

PW_TOKEN=$(echo "$PW_SHARE_RES" | jq -r '.data.token // empty')
PW_HAS=$(echo "$PW_SHARE_RES" | jq -r '.data.has_password')

if [ -n "$PW_TOKEN" ] && [ "$PW_HAS" = "true" ]; then
  pass "Password-protected share created with token ${PW_TOKEN}"
else
  fail "Failed to create password protected share: $PW_SHARE_RES"
fi

# Public query should flag requires_password: true
PW_PUB_RES=$(curl -s "${BASE_URL}/api/v1/s/${PW_TOKEN}")
PW_REQ=$(echo "$PW_PUB_RES" | jq -r '.data.requires_password')
if [ "$PW_REQ" = "true" ]; then
  pass "Public metadata flags requires_password = true"
else
  fail "Public metadata should require password: $PW_PUB_RES"
fi

# Unauthenticated download attempt should receive 401 PASSWORD_REQUIRED
DL_FAIL_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/s/${PW_TOKEN}/download")
if [ "$DL_FAIL_CODE" -eq 401 ]; then
  pass "Download without password correctly blocked with HTTP 401"
else
  fail "Expected 401, got $DL_FAIL_CODE"
fi

# Download attempt with wrong password should receive 401
DL_WRONG_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "X-Share-Password: WrongPassword!" \
  "${BASE_URL}/api/v1/s/${PW_TOKEN}/download")
if [ "$DL_WRONG_CODE" -eq 401 ]; then
  pass "Download with wrong password rejected with HTTP 401"
else
  fail "Expected 401, got $DL_WRONG_CODE"
fi

# Unlock via POST /api/v1/s/{token}/unlock
UNLOCK_RES=$(curl -s -X POST "${BASE_URL}/api/v1/s/${PW_TOKEN}/unlock" \
  -H "Content-Type: application/json" \
  -d '{"password":"SecretDoc123!"}')
UNLOCK_TOKEN=$(echo "$UNLOCK_RES" | jq -r '.data.unlock_token // empty')

if [ -n "$UNLOCK_TOKEN" ]; then
  pass "Unlocked share successfully and received unlock_token"
else
  fail "Failed to unlock share: $UNLOCK_RES"
fi

# Download using unlock token in header
DL_UNLOCKED=$(curl -s -H "X-Share-Token: ${UNLOCK_TOKEN}" "${BASE_URL}/api/v1/s/${PW_TOKEN}/download")
if [ "$DL_UNLOCKED" = "$TEST_FILE_CONTENT" ]; then
  pass "Download using X-Share-Token header succeeded"
else
  fail "Download with unlock token failed: $DL_UNLOCKED"
fi

# Download using direct password header
DL_PW_HEADER=$(curl -s -H "X-Share-Password: SecretDoc123!" "${BASE_URL}/api/v1/s/${PW_TOKEN}/download")
if [ "$DL_PW_HEADER" = "$TEST_FILE_CONTENT" ]; then
  pass "Download using X-Share-Password header succeeded"
else
  fail "Download with password header failed"
fi

# ------------------------------------------------------------------------------
# Test 7: Download Disabled Enforcement
# ------------------------------------------------------------------------------
echo "[Test 7] Testing download_enabled = false enforcement..."
NODL_RES=$(curl -s -X POST "${BASE_URL}/api/v1/shares" \
  -H "Authorization: Bearer ${CUST_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"file_id\":\"${FILE_ID}\",\"download_enabled\":false}")

NODL_TOKEN=$(echo "$NODL_RES" | jq -r '.data.token // empty')
NODL_STATUS=$(echo "$NODL_RES" | jq -r '.data.download_enabled')

if [ -n "$NODL_TOKEN" ] && [ "$NODL_STATUS" = "false" ]; then
  pass "View-only share link created (download_enabled = false)"
else
  fail "Failed to create view-only share: $NODL_RES"
fi

NODL_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/s/${NODL_TOKEN}/download")
if [ "$NODL_CODE" -eq 403 ]; then
  pass "Download attempt blocked with HTTP 403 DOWNLOAD_DISABLED"
else
  fail "Expected 403, got $NODL_CODE"
fi

# ------------------------------------------------------------------------------
# Test 8: Max Downloads Limit Enforcement
# ------------------------------------------------------------------------------
echo "[Test 8] Testing max_downloads enforcement..."
MAXDL_RES=$(curl -s -X POST "${BASE_URL}/api/v1/shares" \
  -H "Authorization: Bearer ${CUST_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"file_id\":\"${FILE_ID}\",\"max_downloads\":2}")

MAXDL_TOKEN=$(echo "$MAXDL_RES" | jq -r '.data.token // empty')

# First download
DL1_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/s/${MAXDL_TOKEN}/download")
# Second download
DL2_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/s/${MAXDL_TOKEN}/download")
# Third download (should exceed limit)
DL3_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/s/${MAXDL_TOKEN}/download")

if [ "$DL1_CODE" -eq 200 ] && [ "$DL2_CODE" -eq 200 ] && [ "$DL3_CODE" -eq 410 ]; then
  pass "Max downloads limit enforced: first 2 downloads HTTP 200, 3rd download rejected with HTTP 410"
else
  fail "Max downloads unexpected codes: $DL1_CODE, $DL2_CODE, $DL3_CODE"
fi

# ------------------------------------------------------------------------------
# Test 9: Expiration Enforcement
# ------------------------------------------------------------------------------
echo "[Test 9] Testing share expiration enforcement..."
EXP_RES=$(curl -s -X POST "${BASE_URL}/api/v1/shares" \
  -H "Authorization: Bearer ${CUST_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"file_id\":\"${FILE_ID}\",\"expires_at\":\"2030-01-01T00:00:00Z\"}")

EXP_ID=$(echo "$EXP_RES" | jq -r '.data.id // empty')
EXP_TOKEN=$(echo "$EXP_RES" | jq -r '.data.token // empty')

# Force expire in DB
docker compose exec -T webapp sqlite3 /data/backend.sqlite \
  "UPDATE file_shares SET expires_at = datetime('now', '-1 hour') WHERE id = '${EXP_ID}'"

EXP_GET_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/s/${EXP_TOKEN}")
EXP_DL_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/s/${EXP_TOKEN}/download")

if [ "$EXP_GET_CODE" -eq 410 ] && [ "$EXP_DL_CODE" -eq 410 ]; then
  pass "Expired share link correctly rejected with HTTP 410 Gone for both metadata and download"
else
  fail "Expected 410 for expired share, got GET=$EXP_GET_CODE, DL=$EXP_DL_CODE"
fi

# ------------------------------------------------------------------------------
# Test 10: Owner Revocation Workflow
# ------------------------------------------------------------------------------
echo "[Test 10] Testing owner share revocation workflow..."
REV_RES=$(curl -s -X POST "${BASE_URL}/api/v1/shares" \
  -H "Authorization: Bearer ${CUST_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"file_id\":\"${FILE_ID}\"}")

REV_ID=$(echo "$REV_RES" | jq -r '.data.id // empty')
REV_TOKEN=$(echo "$REV_RES" | jq -r '.data.token // empty')

# Owner lists shares
USER_SHARES=$(curl -s "${BASE_URL}/api/v1/shares" -H "Authorization: Bearer ${CUST_TOKEN}")
COUNT_BEFORE=$(echo "$USER_SHARES" | jq -r '.data.shares | length')

if [ "$COUNT_BEFORE" -gt 0 ]; then
  pass "User can list their active share links ($COUNT_BEFORE active shares found)"
else
  fail "User shares list was empty"
fi

# Owner revokes share
DEL_RES=$(curl -s -X DELETE "${BASE_URL}/api/v1/shares/${REV_ID}" \
  -H "Authorization: Bearer ${CUST_TOKEN}")
IS_REVOKED=$(echo "$DEL_RES" | jq -r '.data.revoked')

if [ "$IS_REVOKED" = "true" ]; then
  pass "Owner successfully revoked share (${REV_ID})"
else
  fail "Failed to revoke share: $DEL_RES"
fi

# Anonymous access to revoked token
REV_GET_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/s/${REV_TOKEN}")
REV_DL_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/v1/s/${REV_TOKEN}/download")

if [ "$REV_GET_CODE" -eq 410 ] && [ "$REV_DL_CODE" -eq 404 -o "$REV_DL_CODE" -eq 410 ]; then
  pass "Revoked share blocked from public metadata and downloads (HTTP 410/404)"
else
  fail "Revoked share check unexpected: GET=$REV_GET_CODE, DL=$REV_DL_CODE"
fi

# ------------------------------------------------------------------------------
# Test 11: Security & Cross-User Permissions & Zero Path Leakage
# ------------------------------------------------------------------------------
echo "[Test 11] Verifying path concealment and authorization isolation..."

# Register second customer
CUST2_USER="share_attacker_${RAND_SUFFIX}"
CUST2_PASS="AttackerPass123!"
REG2_RES=$(curl -s -X POST "${BASE_URL}/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"${CUST2_USER}\",\"email\":\"${CUST2_USER}@example.com\",\"password\":\"${CUST2_PASS}\"}")
CUST2_TOKEN=$(echo "$REG2_RES" | jq -r '.data.token // .data.session_token // empty')

# Attacker attempts to delete owner's share
ATTACK_DEL=$(curl -s -o /dev/null -w "%{http_code}" -X DELETE "${BASE_URL}/api/v1/shares/${SHARE_ID}" \
  -H "Authorization: Bearer ${CUST2_TOKEN}")
if [ "$ATTACK_DEL" -eq 404 ]; then
  pass "Unauthorized user cannot revoke another user's share (HTTP 404)"
else
  fail "Expected 404 for unauthorized revocation, got $ATTACK_DEL"
fi

# Attacker attempts to share owner's file
ATTACK_SHARE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "${BASE_URL}/api/v1/shares" \
  -H "Authorization: Bearer ${CUST2_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"file_id\":\"${FILE_ID}\"}")
if [ "$ATTACK_SHARE" -eq 422 -o "$ATTACK_SHARE" -eq 403 -o "$ATTACK_SHARE" -eq 404 ]; then
  pass "Unauthorized user cannot create share link for another user's file ($ATTACK_SHARE)"
else
  fail "Attacker could create share for someone else's file: code $ATTACK_SHARE"
fi

# Check headers and body for path leakages
HEADERS_AND_BODY=$(curl -s -i "${BASE_URL}/api/v1/s/${SHARE_TOKEN}/download")
if echo "$HEADERS_AND_BODY" | grep -iq "storage/users\|bigstore:8080\|data/storage"; then
  fail "LEAK DETECTED: Response leaked internal storage path or BigStore host"
else
  pass "Strict zero-leakage verified: no internal BigStore host or filesystem paths exposed"
fi

echo "========================================================"
echo "    Phase 8 File Sharing Results: $PASS_COUNT passed, $FAIL_COUNT failed"
echo "========================================================"

if [ "$FAIL_COUNT" -gt 0 ]; then
  exit 1
fi
