#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
PASSED=0
FAILED=0

echo "========================================================"
echo "    Running Phase 4 BigStore & Storage Test Suite       "
echo "========================================================"

fail() {
  echo "  ❌ FAIL: $1"
  FAILED=$((FAILED + 1))
}

pass() {
  echo "  ✔ PASS: $1"
  PASSED=$((PASSED + 1))
}

# 1. Login as Customer to get auth token
echo "[Test 1] Authenticating customer user..."
LOGIN_RES=$(curl -s -X POST "${BASE_URL}/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"customer","password":"CustomerPass123!"}')

TOKEN=$(echo "${LOGIN_RES}" | jq -r '.data.token // empty')
USER_ID=$(echo "${LOGIN_RES}" | jq -r '.data.user.id // empty')

if [ -n "${TOKEN}" ] && [ -n "${USER_ID}" ]; then
  pass "Authenticated customer: ${USER_ID}"
else
  fail "Could not authenticate customer. Response: ${LOGIN_RES}"
  exit 1
fi

AUTH_HEADER="Authorization: Bearer ${TOKEN}"

# Ensure test customer has an active subscription for Phase 5+ storage access
docker compose exec -T webapp php -r "
    require 'vendor/autoload.php';
    use App\Core\Database;
    \$pdo = Database::getConnection();
    \$stmt = \$pdo->prepare('INSERT OR REPLACE INTO subscriptions (id, user_id, partner_id, plan_id, status, current_period_start, current_period_end) VALUES (\"sub_test_storage\", \"usr_cust_00000001\", \"par_000000000000000000000001\", \"plan_storage_50gib\", \"ACTIVE\", datetime(\"now\"), datetime(\"now\", \"+30 days\"))');
    \$stmt->execute();
" >/dev/null 2>&1 || true

# 2. Get initial quota
echo "[Test 2] Querying storage quota..."
QUOTA_RES=$(curl -s -X GET "${BASE_URL}/api/v1/storage/quota" -H "${AUTH_HEADER}")
if echo "${QUOTA_RES}" | grep -q '"success":true' && echo "${QUOTA_RES}" | grep -q '"quota_bytes":53687091200'; then
  pass "Initial quota is 50 GiB (53687091200 bytes)"
else
  fail "Failed to retrieve valid quota: ${QUOTA_RES}"
fi

# 3. Create root directory
echo "[Test 3] Creating root directory..."
MKDIR_RES=$(curl -s -X POST "${BASE_URL}/api/v1/directories" \
  -H "${AUTH_HEADER}" \
  -H "Content-Type: application/json" \
  -d '{"name":"Workspace"}')

DIR_ID=$(echo "${MKDIR_RES}" | jq -r '.data.id // empty')
if echo "${MKDIR_RES}" | grep -q '"success":true' && [ -n "${DIR_ID}" ]; then
  pass "Created directory Workspace (ID: ${DIR_ID})"
else
  fail "Directory creation failed: ${MKDIR_RES}"
  exit 1
fi

# 4. Create child directory
echo "[Test 4] Creating child directory..."
SUBDIR_RES=$(curl -s -X POST "${BASE_URL}/api/v1/directories" \
  -H "${AUTH_HEADER}" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Documents\",\"parent_id\":\"${DIR_ID}\"}")

SUBDIR_ID=$(echo "${SUBDIR_RES}" | jq -r '.data.id // empty')
if echo "${SUBDIR_RES}" | grep -q '"success":true' && [ -n "${SUBDIR_ID}" ]; then
  pass "Created sub-directory Documents (ID: ${SUBDIR_ID})"
else
  fail "Sub-directory creation failed: ${SUBDIR_RES}"
  exit 1
fi

# 5. List files and directories
echo "[Test 5] Listing directory contents..."
LIST_RES=$(curl -s -X GET "${BASE_URL}/api/v1/files" -H "${AUTH_HEADER}")
if echo "${LIST_RES}" | grep -q "Workspace"; then
  pass "Directory listing contains Workspace"
else
  fail "Workspace missing from listing: ${LIST_RES}"
fi

# 6. Direct multipart upload
echo "[Test 6] Direct single-step multipart file upload..."
TMP_TEST_FILE=$(mktemp)
echo "Direct single-step upload content string 12345" > "${TMP_TEST_FILE}"
DIRECT_FILE_SIZE=$(wc -c < "${TMP_TEST_FILE}")
DIRECT_HASH=$(sha256sum "${TMP_TEST_FILE}" | cut -d' ' -f1)

DIRECT_UPLOAD_RES=$(curl -s -X POST "${BASE_URL}/api/v1/files/upload" \
  -H "${AUTH_HEADER}" \
  -F "file=@${TMP_TEST_FILE};filename=direct_test.txt" \
  -F "directory_id=${DIR_ID}")

DIRECT_FILE_ID=$(echo "${DIRECT_UPLOAD_RES}" | jq -r '.data.id // empty')
rm -f "${TMP_TEST_FILE}"

if echo "${DIRECT_UPLOAD_RES}" | grep -q '"success":true' && [ -n "${DIRECT_FILE_ID}" ]; then
  pass "Direct upload succeeded (File ID: ${DIRECT_FILE_ID}, Hash: ${DIRECT_HASH})"
else
  fail "Direct upload failed: ${DIRECT_UPLOAD_RES}"
  exit 1
fi

# 7. Chunked streaming upload
echo "[Test 7] Chunked streaming upload with SHA-256 verification..."
CHUNK_PART_A="First segment of chunked data payload for storage engine verification. "
CHUNK_PART_B="Second segment adding binary bits and pieces. "
CHUNK_PART_C="Third and final segment completing the streamed object file."
CHUNKED_FULL_CONTENT="${CHUNK_PART_A}${CHUNK_PART_B}${CHUNK_PART_C}"
CHUNKED_TOTAL_SIZE=${#CHUNKED_FULL_CONTENT}
CHUNKED_EXPECTED_HASH=$(printf "%s" "${CHUNKED_FULL_CONTENT}" | sha256sum | cut -d' ' -f1)

INIT_RES=$(curl -s -X POST "${BASE_URL}/api/v1/files/upload/init" \
  -H "${AUTH_HEADER}" \
  -H "Content-Type: application/json" \
  -d "{
    \"original_name\":\"chunked_stream.txt\",
    \"total_size_bytes\":${CHUNKED_TOTAL_SIZE},
    \"total_chunks\":3,
    \"mime_type\":\"text/plain\",
    \"directory_id\":\"${SUBDIR_ID}\",
    \"sha256\":\"${CHUNKED_EXPECTED_HASH}\"
  }")

UPLOAD_ID=$(echo "${INIT_RES}" | jq -r '.data.upload_id // empty')
if [ -n "${UPLOAD_ID}" ]; then
  pass "Initialized chunked upload session (${UPLOAD_ID})"
else
  fail "Chunked upload init failed: ${INIT_RES}"
  exit 1
fi

# Upload chunk 0
C0_RES=$(curl -s -X PUT "${BASE_URL}/api/v1/files/upload/${UPLOAD_ID}/chunk/0" \
  -H "${AUTH_HEADER}" \
  -H "Content-Type: application/octet-stream" \
  --data-binary "${CHUNK_PART_A}")

# Upload chunk 1
C1_RES=$(curl -s -X PUT "${BASE_URL}/api/v1/files/upload/${UPLOAD_ID}/chunk/1" \
  -H "${AUTH_HEADER}" \
  -H "Content-Type: application/octet-stream" \
  --data-binary "${CHUNK_PART_B}")

# Upload chunk 2
C2_RES=$(curl -s -X PUT "${BASE_URL}/api/v1/files/upload/${UPLOAD_ID}/chunk/2" \
  -H "${AUTH_HEADER}" \
  -H "Content-Type: application/octet-stream" \
  --data-binary "${CHUNK_PART_C}")

if echo "${C2_RES}" | grep -q '"is_complete":true'; then
  pass "All 3 chunks uploaded successfully"
else
  fail "Chunk upload incomplete: ${C2_RES}"
fi

# Finalize upload
FINALIZE_RES=$(curl -s -X POST "${BASE_URL}/api/v1/files/upload/${UPLOAD_ID}/finalize" \
  -H "${AUTH_HEADER}" \
  -H "Content-Type: application/json" \
  -d "{\"expected_sha256\":\"${CHUNKED_EXPECTED_HASH}\"}")

CHUNKED_FILE_ID=$(echo "${FINALIZE_RES}" | jq -r '.data.id // empty')
if echo "${FINALIZE_RES}" | grep -q '"success":true' && [ -n "${CHUNKED_FILE_ID}" ]; then
  pass "Chunked assembly finalized with verified SHA-256 (File ID: ${CHUNKED_FILE_ID})"
else
  fail "Chunked finalize failed: ${FINALIZE_RES}"
  exit 1
fi

# 8. Download file and verify content
echo "[Test 8] Downloading file and verifying content..."
DOWNLOADED_CONTENT=$(curl -s -X GET "${BASE_URL}/api/v1/files/${CHUNKED_FILE_ID}/download" -H "${AUTH_HEADER}")
if [ "${DOWNLOADED_CONTENT}" = "${CHUNKED_FULL_CONTENT}" ]; then
  pass "Downloaded content matches original chunked stream byte-for-byte"
else
  fail "Downloaded content mismatch. Got: '${DOWNLOADED_CONTENT}'"
fi

# 9. HTTP 206 Range Streaming
echo "[Test 9] HTTP 206 Partial Content Range streaming..."
RANGE_HEADER_RES=$(curl -s -i -X GET "${BASE_URL}/api/v1/files/${CHUNKED_FILE_ID}/stream" \
  -H "${AUTH_HEADER}" \
  -H "Range: bytes=0-12")

if echo "${RANGE_HEADER_RES}" | grep -qi "206 Partial Content" && echo "${RANGE_HEADER_RES}" | grep -qi "Content-Range: bytes 0-12/${CHUNKED_TOTAL_SIZE}"; then
  pass "HTTP 206 Partial Content Range streaming returned requested 13-byte slice"
else
  fail "HTTP Range response invalid: ${RANGE_HEADER_RES}"
fi

# 10. Storage account accounting
echo "[Test 10] Storage quota accounting update..."
QUOTA_AFTER_RES=$(curl -s -X GET "${BASE_URL}/api/v1/storage/quota" -H "${AUTH_HEADER}")
TOTAL_EXPECTED_USED=$((DIRECT_FILE_SIZE + CHUNKED_TOTAL_SIZE))
if echo "${QUOTA_AFTER_RES}" | grep -q "\"used_bytes\":${TOTAL_EXPECTED_USED}"; then
  pass "Storage usage correctly reflects ${TOTAL_EXPECTED_USED} bytes"
else
  fail "Storage usage mismatch. Response: ${QUOTA_AFTER_RES}, expected used: ${TOTAL_EXPECTED_USED}"
fi

# 11. Storage Quota Overflow rejection
echo "[Test 11] Storage quota overflow rejection..."
HUGE_SIZE=60000000000 # 60 GB
OVERFLOW_RES=$(curl -s -X POST "${BASE_URL}/api/v1/files/upload/init" \
  -H "${AUTH_HEADER}" \
  -H "Content-Type: application/json" \
  -d "{\"original_name\":\"huge.iso\",\"total_size_bytes\":${HUGE_SIZE},\"total_chunks\":1}")

if echo "${OVERFLOW_RES}" | grep -q "QUOTA_EXCEEDED"; then
  pass "Upload exceeding quota rejected with QUOTA_EXCEEDED"
else
  fail "Overflow was not rejected: ${OVERFLOW_RES}"
fi

# 12. Cross-user isolation
echo "[Test 12] Cross-user file access isolation..."
# Register a second customer
USER2_RES=$(curl -s -X POST "${BASE_URL}/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"user_iso_$RANDOM\",\"email\":\"iso_$RANDOM@example.com\",\"password\":\"SecureP@ss123!\"}")

USER2_TOKEN=$(echo "${USER2_RES}" | jq -r '.data.token // empty')

CROSS_ACCESS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X GET "${BASE_URL}/api/v1/files/${CHUNKED_FILE_ID}" \
  -H "Authorization: Bearer ${USER2_TOKEN}")

if [ "${CROSS_ACCESS_STATUS}" = "404" ] || [ "${CROSS_ACCESS_STATUS}" = "403" ]; then
  pass "User 2 cannot access User 1's file (HTTP ${CROSS_ACCESS_STATUS})"
else
  fail "Cross-user isolation broken! HTTP code: ${CROSS_ACCESS_STATUS}"
fi

# 13. File Deletion and quota reclaim
echo "[Test 13] File deletion and quota reclaim..."
DELETE_FILE_RES=$(curl -s -X DELETE "${BASE_URL}/api/v1/files/${CHUNKED_FILE_ID}" -H "${AUTH_HEADER}")
if echo "${DELETE_FILE_RES}" | grep -q '"success":true'; then
  pass "File deleted successfully"
else
  fail "File deletion failed: ${DELETE_FILE_RES}"
fi

QUOTA_FINAL_RES=$(curl -s -X GET "${BASE_URL}/api/v1/storage/quota" -H "${AUTH_HEADER}")
if echo "${QUOTA_FINAL_RES}" | grep -q "\"used_bytes\":${DIRECT_FILE_SIZE}"; then
  pass "Storage usage correctly reclaimed deleted file bytes (${DIRECT_FILE_SIZE} bytes remaining)"
else
  fail "Storage quota reclaim failed: ${QUOTA_FINAL_RES}"
fi

# 14. Directory Deletion
echo "[Test 14] Directory deletion..."
DELETE_DIR_RES=$(curl -s -X DELETE "${BASE_URL}/api/v1/directories/${DIR_ID}" -H "${AUTH_HEADER}")
if echo "${DELETE_DIR_RES}" | grep -q '"success":true'; then
  pass "Directory deleted successfully"
else
  fail "Directory deletion failed: ${DELETE_DIR_RES}"
fi

echo "========================================================"
echo "    Phase 4 BigStore Results: ${PASSED} passed, ${FAILED} failed"
echo "========================================================"

if [ "${FAILED}" -gt 0 ]; then
  exit 1
fi
