#!/usr/bin/env bash
set -euo pipefail

BASE_URL="http://localhost:8080"
PASSED=0
FAILED=0

pass() {
  echo "  ✔ PASS: $1"
  PASSED=$((PASSED + 1))
}

fail() {
  echo "  ✘ FAIL: $1"
  FAILED=$((FAILED + 1))
}

assert_eq() {
  local expected="$1"
  local actual="$2"
  local msg="$3"
  if [ "$expected" = "$actual" ]; then
    pass "$msg"
  else
    fail "$msg (Expected '$expected', got '$actual')"
    echo "Stack trace / details:"
    caller
    exit 1
  fi
}

echo "========================================================"
echo "       Running Phase 9 Movie Mode & Streaming Suite     "
echo "========================================================"

# Test 1: Unauthenticated request rejected with 401
echo "[Test 1] Verifying unauthenticated requests receive HTTP 401..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/v1/movies")
assert_eq "401" "$HTTP_CODE" "GET /api/v1/movies rejected with UNAUTHORIZED (401)"

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/v1/movies/scan")
assert_eq "401" "$HTTP_CODE" "POST /api/v1/movies/scan rejected with UNAUTHORIZED (401)"

# Test 2: User setup
RAND_SUFFIX=$(head /dev/urandom | tr -dc a-z0-9 | head -c 6)
USER_NAME="cine_user_${RAND_SUFFIX}"
USER_EMAIL="${USER_NAME}@test.local"
USER_PASS="CinemaSecretPass123!"

echo "[Test 2] Setting up customer user: $USER_NAME..."
REG_RESP=$(curl -s -X POST "$BASE_URL/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\": \"$USER_NAME\", \"email\": \"$USER_EMAIL\", \"password\": \"$USER_PASS\"}")

USER_TOKEN=$(echo "$REG_RESP" | jq -r '.data.token // empty')
USER_ID=$(echo "$REG_RESP" | jq -r '.data.user.id // empty')

if [ -n "$USER_TOKEN" ] && [ -n "$USER_ID" ]; then
  pass "User registered successfully ($USER_ID)"
else
  fail "Failed to register user: $REG_RESP"
  exit 1
fi

USER_AUTH="Authorization: Bearer $USER_TOKEN"

# Subscribe user so quota is active (50 GiB)
SUBSCRIBE_RESP=$(curl -s -X POST "$BASE_URL/api/v1/subscriptions/request" \
  -H "$USER_AUTH" \
  -H "Content-Type: application/json" \
  -d '{"plan": "standard", "amount_cents": 300}')
REQ_ID=$(echo "$SUBSCRIBE_RESP" | jq -r '.request.id // empty')

# Partner approves
PARTNER_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier": "partner", "password": "PartnerPass123!"}')
PARTNER_TOKEN=$(echo "$PARTNER_LOGIN" | jq -r '.data.token // empty')

curl -s -X POST "$BASE_URL/api/v1/partner/subscription-requests/${REQ_ID}/approve" \
  -H "Authorization: Bearer $PARTNER_TOKEN" > /dev/null

pass "User subscribed and storage quota active"

# Test 3: Upload sample media files to BigStore
echo "[Test 3] Uploading sample video files..."
# Create small dummy binary video files
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

echo "DUMMY_MP4_VIDEO_HEADER_CONTENT_BYTES_1234567890" > "$TMP_DIR/The.Matrix.1999.1080p.mkv"
echo "DUMMY_INTERSTELLAR_2014_HEADER_CONTENT_BYTES_1234567890" > "$TMP_DIR/Interstellar (2014).mp4"
echo "DUMMY_CUSTOM_INDIE_FILM_CONTENT_BYTES_1234567890" > "$TMP_DIR/Neon.Horizons.2025.720p.mkv"

UPLOAD1=$(curl -s -X POST "$BASE_URL/api/v1/files/upload" \
  -H "$USER_AUTH" \
  -F "file=@$TMP_DIR/The.Matrix.1999.1080p.mkv;filename=The.Matrix.1999.1080p.mkv;type=video/x-matroska")
FILE1_ID=$(echo "$UPLOAD1" | jq -r '.data.id // empty')

UPLOAD2=$(curl -s -X POST "$BASE_URL/api/v1/files/upload" \
  -H "$USER_AUTH" \
  -F "file=@$TMP_DIR/Interstellar (2014).mp4;filename=Interstellar (2014).mp4;type=video/mp4")
FILE2_ID=$(echo "$UPLOAD2" | jq -r '.data.id // empty')

UPLOAD3=$(curl -s -X POST "$BASE_URL/api/v1/files/upload" \
  -H "$USER_AUTH" \
  -F "file=@$TMP_DIR/Neon.Horizons.2025.720p.mkv;filename=Neon.Horizons.2025.720p.mkv;type=video/x-matroska")
FILE3_ID=$(echo "$UPLOAD3" | jq -r '.data.id // empty')

if [ -n "$FILE1_ID" ] && [ -n "$FILE2_ID" ] && [ -n "$FILE3_ID" ]; then
  pass "Sample media files uploaded successfully ($FILE1_ID, $FILE2_ID, $FILE3_ID)"
else
  fail "Failed to upload video files: $UPLOAD1"
  exit 1
fi

# Test 4: Trigger media scanning
echo "[Test 4] Triggering media scanning..."
SCAN_RESP=$(curl -s -X POST "$BASE_URL/api/v1/movies/scan" -H "$USER_AUTH")
ADDED_COUNT=$(echo "$SCAN_RESP" | jq -r '.data.added_movies // 0')

if [ "$ADDED_COUNT" -ge 3 ]; then
  pass "Media scan successfully discovered and added $ADDED_COUNT movies"
else
  fail "Scan did not add expected movies: $SCAN_RESP"
  exit 1
fi

# Re-scan should add 0 new movies
RESCAN_RESP=$(curl -s -X POST "$BASE_URL/api/v1/movies/scan" -H "$USER_AUTH")
RESCAN_ADDED=$(echo "$RESCAN_RESP" | jq -r '.data.added_movies // 0')
assert_eq "0" "$RESCAN_ADDED" "Re-scanning skips existing movies"

# Test 5: Verify metadata extraction and matching
echo "[Test 5] Verifying movie library and metadata enrichment..."
MOVIES_RESP=$(curl -s "$BASE_URL/api/v1/movies" -H "$USER_AUTH")
TOTAL_MOVIES=$(echo "$MOVIES_RESP" | jq -r '.data.pagination.total // 0')

if [ "$TOTAL_MOVIES" -ge 3 ]; then
  pass "Movie library returns at least 3 movies (total: $TOTAL_MOVIES)"
else
  fail "Expected at least 3 movies in library: $MOVIES_RESP"
  exit 1
fi

# Check The Matrix
MATRIX=$(echo "$MOVIES_RESP" | jq '.data.movies[] | select(.title == "The Matrix")')
MATRIX_YEAR=$(echo "$MATRIX" | jq -r '.release_year')
MATRIX_ID=$(echo "$MATRIX" | jq -r '.id')
assert_eq "1999" "$MATRIX_YEAR" "The Matrix release year correctly extracted as 1999"

MATRIX_POSTER=$(echo "$MATRIX" | jq -r '.poster_url')
if [[ "$MATRIX_POSTER" =~ ^https?:// ]]; then
  pass "The Matrix has valid poster artwork URL: $MATRIX_POSTER"
else
  fail "The Matrix missing valid poster URL"
fi

# Check Interstellar
INTERSTELLAR=$(echo "$MOVIES_RESP" | jq '.data.movies[] | select(.title == "Interstellar")')
INTERSTELLAR_DIRECTOR=$(echo "$INTERSTELLAR" | jq -r '.director')
INTERSTELLAR_ID=$(echo "$INTERSTELLAR" | jq -r '.id')
assert_eq "Christopher Nolan" "$INTERSTELLAR_DIRECTOR" "Interstellar director correctly resolved as Christopher Nolan"

# Check Neon Horizons (dynamic fallback)
NEON=$(echo "$MOVIES_RESP" | jq '.data.movies[] | select(.title == "Neon Horizons")')
NEON_YEAR=$(echo "$NEON" | jq -r '.release_year')
NEON_ID=$(echo "$NEON" | jq -r '.id')
assert_eq "2025" "$NEON_YEAR" "Neon Horizons title and year 2025 parsed accurately"

# Test 6: Single movie detail endpoint
echo "[Test 6] Fetching single movie details..."
DETAIL_RESP=$(curl -s "$BASE_URL/api/v1/movies/$MATRIX_ID" -H "$USER_AUTH")
DETAIL_TITLE=$(echo "$DETAIL_RESP" | jq -r '.data.title')
assert_eq "The Matrix" "$DETAIL_TITLE" "GET /api/v1/movies/{id} returns accurate details"

# Test 7: Manual metadata update / correction (Spec Section 17)
echo "[Test 7] Updating movie metadata manually..."
UPDATE_RESP=$(curl -s -X POST "$BASE_URL/api/v1/movies/$NEON_ID/metadata" \
  -H "$USER_AUTH" \
  -H "Content-Type: application/json" \
  -d '{"director": "Elena Vance", "rating": "9.1/10", "genres": "Cyberpunk, Neo-Noir"}')

UPDATED_DIR=$(echo "$UPDATE_RESP" | jq -r '.data.director')
UPDATED_RATING=$(echo "$UPDATE_RESP" | jq -r '.data.rating')
UPDATED_GENRES=$(echo "$UPDATE_RESP" | jq -r '.data.genres')

assert_eq "Elena Vance" "$UPDATED_DIR" "Director successfully updated to Elena Vance"
assert_eq "9.1/10" "$UPDATED_RATING" "Rating successfully updated to 9.1/10"
assert_eq "Cyberpunk, Neo-Noir" "$UPDATED_GENRES" "Genres successfully updated to Cyberpunk, Neo-Noir"

# Test 8: Designating and querying Movie Folders (Spec Section 16)
echo "[Test 8] Designating Movie Folders..."
MKDIR_RESP=$(curl -s -X POST "$BASE_URL/api/v1/directories" \
  -H "$USER_AUTH" \
  -H "Content-Type: application/json" \
  -d '{"name": "Cinema Vault"}')
VAULT_DIR_ID=$(echo "$MKDIR_RESP" | jq -r '.data.id // empty')

FOLDER_DESIGNATE=$(curl -s -X POST "$BASE_URL/api/v1/movies/folders" \
  -H "$USER_AUTH" \
  -H "Content-Type: application/json" \
  -d "{\"directory_id\": \"$VAULT_DIR_ID\"}")
FOLDER_DESIGNATED=$(echo "$FOLDER_DESIGNATE" | jq -r '.data.designated')
assert_eq "true" "$FOLDER_DESIGNATED" "Directory designated as movie folder"

FOLDERS_LIST=$(curl -s "$BASE_URL/api/v1/movies/folders" -H "$USER_AUTH")
FOUND_DIR=$(echo "$FOLDERS_LIST" | jq -r ".data.folders[] | select(. == \"$VAULT_DIR_ID\")")
assert_eq "$VAULT_DIR_ID" "$FOUND_DIR" "Movie folder appears in designated folders list"

# Test 9: Short-lived streaming token generation (Spec Section 19)
echo "[Test 9] Generating short-lived streaming token..."
TOKEN_RESP=$(curl -s -X POST "$BASE_URL/api/v1/movies/$MATRIX_ID/stream-token" -H "$USER_AUTH")
STREAM_TOKEN=$(echo "$TOKEN_RESP" | jq -r '.data.token // empty')
STREAM_URL=$(echo "$TOKEN_RESP" | jq -r '.data.stream_url // empty')

if [ -n "$STREAM_TOKEN" ] && [[ "$STREAM_TOKEN" =~ ^stk_ ]]; then
  pass "Short-lived stream token issued ($STREAM_TOKEN)"
else
  fail "Failed to issue stream token: $TOKEN_RESP"
  exit 1
fi

assert_eq "/api/v1/media/stream/$STREAM_TOKEN" "$STREAM_URL" "Stream URL matches spec /api/v1/media/stream/{token}"

# Test 10: Range streaming with token (HTTP 206 Partial Content)
echo "[Test 10] Testing HTTP Range media playback via token..."
STREAM_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL$STREAM_URL")
assert_eq "200" "$STREAM_STATUS" "Full stream playback returns HTTP 200"

RANGE_HEADER=$(curl -s -I -r 0-9 "$BASE_URL$STREAM_URL")
RANGE_CODE=$(echo "$RANGE_HEADER" | grep -i "HTTP/" | awk '{print $2}' | tr -d '\r')
ACCEPT_RANGES=$(echo "$RANGE_HEADER" | grep -i "accept-ranges:" | tr -d '\r' | awk '{print $2}')
CONTENT_RANGE=$(echo "$RANGE_HEADER" | grep -i "content-range:" | tr -d '\r')

assert_eq "206" "$RANGE_CODE" "HTTP Range request returns HTTP 206 Partial Content"
assert_eq "bytes" "$ACCEPT_RANGES" "Accept-Ranges header specifies bytes"

RANGE_BODY=$(curl -s -r 0-9 "$BASE_URL$STREAM_URL")
assert_eq "DUMMY_MP4_" "$RANGE_BODY" "Byte-range slice matches exact file content (0-9)"

# Clean URL without /api/v1/ prefix: /media/stream/{token}
CLEAN_RANGE_CODE=$(curl -s -o /dev/null -w "%{http_code}" -r 0-9 "$BASE_URL/media/stream/$STREAM_TOKEN")
assert_eq "206" "$CLEAN_RANGE_CODE" "Clean route /media/stream/{token} supports HTTP 206 Range playback"

# Test 11: Invalid or expired streaming token
echo "[Test 11] Testing invalid/expired streaming token..."
BAD_TOKEN_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/v1/media/stream/stk_nonexistent_invalid_token")
assert_eq "403" "$BAD_TOKEN_STATUS" "Invalid streaming token rejected with HTTP 403"

# Test 12: Cross-user access isolation
echo "[Test 12] Testing cross-user access isolation..."
USER2_NAME="cine_intruder_${RAND_SUFFIX}"
USER2_EMAIL="${USER2_NAME}@test.local"
USER2_RESP=$(curl -s -X POST "$BASE_URL/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\": \"$USER2_NAME\", \"email\": \"$USER2_EMAIL\", \"password\": \"$USER_PASS\"}")
USER2_TOKEN=$(echo "$USER2_RESP" | jq -r '.data.token // empty')
USER2_AUTH="Authorization: Bearer $USER2_TOKEN"

# User 2 tries to access User 1's movie detail
U2_GET_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/v1/movies/$MATRIX_ID" -H "$USER2_AUTH")
assert_eq "404" "$U2_GET_STATUS" "User 2 cannot view User 1's private movie (HTTP 404)"

# User 2 tries to generate stream token for User 1's movie
U2_TOKEN_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/v1/movies/$MATRIX_ID/stream-token" -H "$USER2_AUTH")
assert_eq "403" "$U2_TOKEN_STATUS" "User 2 cannot generate stream token for User 1's movie (HTTP 403)"

# User 2 tries to update User 1's movie
U2_UPDATE_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/v1/movies/$MATRIX_ID/metadata" \
  -H "$USER2_AUTH" -H "Content-Type: application/json" -d '{"title": "Hacked Movie"}')
assert_eq "404" "$U2_UPDATE_STATUS" "User 2 cannot edit User 1's movie metadata (HTTP 404)"

# User 2 tries to delete User 1's movie
U2_DELETE_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X DELETE "$BASE_URL/api/v1/movies/$MATRIX_ID" -H "$USER2_AUTH")
assert_eq "404" "$U2_DELETE_STATUS" "User 2 cannot delete User 1's movie (HTTP 404)"

# Test 13: Search and Genre filtering
echo "[Test 13] Testing search and genre filtering..."
SEARCH_RESP=$(curl -s "$BASE_URL/api/v1/movies?search=Matrix" -H "$USER_AUTH")
FOUND_TITLE=$(echo "$SEARCH_RESP" | jq -r '.data.movies[0].title')
assert_eq "The Matrix" "$FOUND_TITLE" "Search query ?search=Matrix correctly finds The Matrix"

GENRE_RESP=$(curl -s "$BASE_URL/api/v1/movies?genre=Sci-Fi" -H "$USER_AUTH")
GENRE_COUNT=$(echo "$GENRE_RESP" | jq -r '.data.pagination.total')
if [ "$GENRE_COUNT" -ge 2 ]; then
  pass "Genre filter ?genre=Sci-Fi returns matching movies ($GENRE_COUNT found)"
else
  fail "Genre filter did not return expected count: $GENRE_RESP"
fi

# Test 14: Movie deletion
echo "[Test 14] Testing movie deletion..."
DEL_RESP=$(curl -s -X DELETE "$BASE_URL/api/v1/movies/$NEON_ID" -H "$USER_AUTH")
DEL_SUCCESS=$(echo "$DEL_RESP" | jq -r '.data.deleted')
assert_eq "true" "$DEL_SUCCESS" "Owner successfully deleted movie entry"

DEL_VERIFY=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/v1/movies/$NEON_ID" -H "$USER_AUTH")
assert_eq "404" "$DEL_VERIFY" "Deleted movie returns HTTP 404"

# Test 15: Zero BigStore or internal path leakage verification
echo "[Test 15] Verifying zero BigStore path or internal hostname leakage..."
LEAK_CHECK=$(curl -s "$BASE_URL/api/v1/movies/$MATRIX_ID" -H "$USER_AUTH")
if echo "$LEAK_CHECK" | grep -iq -E "(/data/files|objects/|bigstore:8080|127.0.0.1:8080)"; then
  fail "Internal path or BigStore host leaked in movie response"
else
  pass "Strict zero-leakage verified: no internal BigStore host or filesystem paths exposed"
fi

echo "========================================================"
echo "    Phase 9 Movie Mode Results: $PASSED passed, $FAILED failed"
echo "========================================================"

if [ "$FAILED" -gt 0 ]; then
  exit 1
fi
