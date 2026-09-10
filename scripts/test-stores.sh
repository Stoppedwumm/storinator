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

assert_not_empty() {
  local val="$1"
  local msg="$2"
  if [ -n "$val" ] && [ "$val" != "null" ]; then
    pass "$msg"
  else
    fail "$msg (Value is empty or null)"
    exit 1
  fi
}

echo "========================================================"
echo "         Running Phase 10 Stores & Merchant Suite       "
echo "========================================================"

# Test 1: Unauthenticated access to public stores
echo "[Test 1] Verifying public unauthenticated access to store directory..."
STORES_RESP=$(curl -s "$BASE_URL/api/v1/stores")
STORES_OK=$(echo "$STORES_RESP" | jq -r '.success // false')
assert_eq "true" "$STORES_OK" "Public GET /api/v1/stores returns success without authentication"

# Test 2: Unauthenticated partner store management rejected
echo "[Test 2] Verifying unauthenticated partner operations receive HTTP 401..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/v1/partner/stores" \
  -H "Content-Type: application/json" -d '{"name":"Hacker Store"}')
assert_eq "401" "$HTTP_CODE" "POST /api/v1/partner/stores rejected with UNAUTHORIZED (401)"

# Test 3: Customer role blocked from partner store management
echo "[Test 3] Verifying customer is blocked from partner store endpoints..."
CUST_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"customer","password":"CustomerPass123!"}')
CUST_TOKEN=$(echo "$CUST_LOGIN" | jq -r '.data.token // empty')
assert_not_empty "$CUST_TOKEN" "Customer successfully authenticated"

CUST_STORE_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Customer Invalid Store"}')
CUST_ERR=$(echo "$CUST_STORE_RESP" | jq -r '.error.code // empty')
assert_eq "FORBIDDEN" "$CUST_ERR" "Customer forbidden from creating stores (403)"

# Test 4: Partner Authentication
echo "[Test 4] Logging in as primary partner..."
PARTNER_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"partner","password":"PartnerPass123!"}')
PARTNER_TOKEN=$(echo "$PARTNER_LOGIN" | jq -r '.data.token // empty')
assert_not_empty "$PARTNER_TOKEN" "Partner authenticated successfully"

# Test 5: Partner Store Creation & Branding
RAND_SUFFIX=$(head /dev/urandom | tr -dc a-z0-9 | head -c 6)
STORE_NAME="Tech Emporium $RAND_SUFFIX"
STORE_SLUG="tech-emporium-$RAND_SUFFIX"

echo "[Test 5] Creating branded partner store '$STORE_NAME'..."
CREATE_STORE_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"$STORE_NAME\",
    \"slug\": \"$STORE_SLUG\",
    \"description\": \"Next-generation server hardware and cyber accessories\",
    \"theme_color\": \"#0ea5e9\",
    \"contact_email\": \"sales@techemporium.io\",
    \"contact_phone\": \"+1-555-0199\",
    \"logo_url\": \"https://images.unsplash.com/photo-1550751827-4bd374c3f58b?w=200\",
    \"banner_url\": \"https://images.unsplash.com/photo-1518770660439-4636190af475?w=1200\",
    \"terms_content\": \"Standard commercial delivery within 48 hours.\"
  }")

STORE_ID=$(echo "$CREATE_STORE_RESP" | jq -r '.data.store.id // empty')
assert_not_empty "$STORE_ID" "Store created with ID ($STORE_ID)"
STORE_THEME=$(echo "$CREATE_STORE_RESP" | jq -r '.data.store.theme_color // empty')
assert_eq "#0ea5e9" "$STORE_THEME" "Store theme color saved correctly"

# Test 6: Duplicate store slug rejection
echo "[Test 6] Verifying duplicate store slug is rejected..."
DUP_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"name\": \"Duplicate Store\", \"slug\": \"$STORE_SLUG\"}")
DUP_ERR=$(echo "$DUP_RESP" | jq -r '.error.code // empty')
assert_eq "VALIDATION_ERROR" "$DUP_ERR" "Duplicate store slug rejected with VALIDATION_ERROR (422)"

# Test 7: Public Storefront Access (NO Subscription required!)
echo "[Test 7] Verifying public storefront access by anonymous visitor..."
PUB_STORE_RESP=$(curl -s "$BASE_URL/api/v1/stores/$STORE_SLUG")
PUB_STORE_NAME=$(echo "$PUB_STORE_RESP" | jq -r '.data.store.name // empty')
assert_eq "$STORE_NAME" "$PUB_STORE_NAME" "Anonymous visitor can view public store details"

# Test 8: Store Category Management
echo "[Test 8] Managing store categories..."
CAT1_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores/$STORE_ID/categories" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Hardware", "sort_order": 1}')
CAT1_ID=$(echo "$CAT1_RESP" | jq -r '.data.category.id // empty')
assert_not_empty "$CAT1_ID" "Category 'Hardware' created ($CAT1_ID)"

CAT2_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores/$STORE_ID/categories" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Accessories", "sort_order": 2}')
CAT2_ID=$(echo "$CAT2_RESP" | jq -r '.data.category.id // empty')
assert_not_empty "$CAT2_ID" "Category 'Accessories' created ($CAT2_ID)"

# Test 9: Product Creation with Rule 6 Integer Minor Units
echo "[Test 9] Creating products with integer minor units (price_cents)..."
# 9.1: Negative price rejected
NEG_PROD_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores/$STORE_ID/products" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Freebie Gone Wrong", "price_cents": -500}')
NEG_ERR=$(echo "$NEG_PROD_RESP" | jq -r '.error.code // empty')
assert_eq "VALIDATION_ERROR" "$NEG_ERR" "Negative price_cents rejected with VALIDATION_ERROR (422)"

# 9.2: Valid products creation
PROD1_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores/$STORE_ID/products" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Quantum SSD 2TB\",
    \"slug\": \"quantum-ssd-2tb\",
    \"category_id\": \"$CAT1_ID\",
    \"short_description\": \"Ultra-fast PCIe Gen5 NVMe storage\",
    \"description\": \"7500 MB/s read, 6800 MB/s write speeds with dedicated heatsink.\",
    \"price_cents\": 18999,
    \"currency\": \"EUR\",
    \"inventory\": 25,
    \"sku\": \"QNT-SSD-2TB\",
    \"images\": [\"https://images.unsplash.com/photo-1597872200969-2b65d56bd16b?w=600\"],
    \"variants\": [{\"name\": \"Capacity\", \"options\": [\"2TB\", \"4TB\"]}]
  }")

PROD1_ID=$(echo "$PROD1_RESP" | jq -r '.data.product.id // empty')
assert_not_empty "$PROD1_ID" "Product 1 created successfully ($PROD1_ID)"
PROD1_FMT_PRICE=$(echo "$PROD1_RESP" | jq -r '.data.product.formatted_price // empty')
assert_eq "189.99 €" "$PROD1_FMT_PRICE" "Product price formatted correctly as minor units (189.99 €)"

PROD2_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores/$STORE_ID/products" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Braided USB-C Cable\",
    \"slug\": \"braided-usbc-cable\",
    \"category_id\": \"$CAT2_ID\",
    \"short_description\": \"Durable 240W braided charging cable\",
    \"price_cents\": 1499,
    \"inventory\": 50,
    \"sku\": \"CBL-USBC-2M\"
  }")
PROD2_ID=$(echo "$PROD2_RESP" | jq -r '.data.product.id // empty')
assert_not_empty "$PROD2_ID" "Product 2 created successfully ($PROD2_ID)"

# Test 10: Product Updating & Inventory Control
echo "[Test 10] Updating product details and inventory..."
UPDATE_PROD_RESP=$(curl -s -X PATCH "$BASE_URL/api/v1/partner/stores/$STORE_ID/products/$PROD1_ID" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"price_cents": 16999, "inventory": 30}')
PROD1_NEW_PRICE=$(echo "$UPDATE_PROD_RESP" | jq -r '.data.product.formatted_price // empty')
assert_eq "169.99 €" "$PROD1_NEW_PRICE" "Product price updated to 169.99 €"
PROD1_NEW_INV=$(echo "$UPDATE_PROD_RESP" | jq -r '.data.product.inventory // empty')
assert_eq "30" "$PROD1_NEW_INV" "Inventory updated to 30 units"

# Test 11: Public Product Catalog & Filtering (NO Subscription Required)
echo "[Test 11] Testing public product catalog queries..."
# 11.1: List all products in store
PUB_PRODS_RESP=$(curl -s "$BASE_URL/api/v1/stores/$STORE_SLUG/products")
PUB_PRODS_COUNT=$(echo "$PUB_PRODS_RESP" | jq '.data.products | length')
assert_eq "2" "$PUB_PRODS_COUNT" "Public catalog lists 2 active products"

# 11.2: Filter by category
CAT1_PRODS_RESP=$(curl -s "$BASE_URL/api/v1/stores/$STORE_SLUG/products?category_id=$CAT1_ID")
CAT1_COUNT=$(echo "$CAT1_PRODS_RESP" | jq '.data.products | length')
assert_eq "1" "$CAT1_COUNT" "Category filter returns exactly 1 matching product"
CAT1_NAME=$(echo "$CAT1_PRODS_RESP" | jq -r '.data.products[0].name')
assert_eq "Quantum SSD 2TB" "$CAT1_NAME" "Filtered category product name matches"

# 11.3: Search products
SEARCH_RESP=$(curl -s "$BASE_URL/api/v1/stores/$STORE_SLUG/products?search=Braided")
SEARCH_COUNT=$(echo "$SEARCH_RESP" | jq '.data.products | length')
assert_eq "1" "$SEARCH_COUNT" "Search query ?search=Braided returns 1 product"

# 11.4: Single product details by slugs
SINGLE_PROD_RESP=$(curl -s "$BASE_URL/api/v1/stores/$STORE_SLUG/products/quantum-ssd-2tb")
SINGLE_PROD_NAME=$(echo "$SINGLE_PROD_RESP" | jq -r '.data.product.name // empty')
assert_eq "Quantum SSD 2TB" "$SINGLE_PROD_NAME" "Public product page retrieved by slug"

# Test 12: Cross-Partner Security Isolation (Spec Section 43 & Rule 13)
echo "[Test 12] Testing cross-partner security isolation..."
# Setup Partner 2
P2_NAME="p2_test_${RAND_SUFFIX}"
P2_REG=$(curl -s -X POST "$BASE_URL/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"username\": \"$P2_NAME\", \"email\": \"$P2_NAME@test.local\", \"password\": \"PartnerPass2026!\"}")
P2_USER_ID=$(echo "$P2_REG" | jq -r '.data.user.id // empty')
assert_not_empty "$P2_USER_ID" "Partner 2 user registered"

# Upgrade Partner 2 to PARTNER role via admin database
docker compose exec -T webapp sqlite3 /data/backend.sqlite \
  "INSERT INTO user_roles (user_id, role_id) VALUES ('$P2_USER_ID', 'PARTNER');"
docker compose exec -T webapp sqlite3 /data/backend.sqlite \
  "INSERT INTO partners (id, user_id, company_name) VALUES ('par_${RAND_SUFFIX}2', '$P2_USER_ID', 'Rival Corp');"

P2_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"identifier\":\"$P2_NAME\",\"password\":\"PartnerPass2026!\"}")
P2_TOKEN=$(echo "$P2_LOGIN" | jq -r '.data.token // empty')
assert_not_empty "$P2_TOKEN" "Partner 2 logged in"

# Partner 2 attempts to update Partner 1's store
P2_HACK_RESP=$(curl -s -X PATCH "$BASE_URL/api/v1/partner/stores/$STORE_ID" \
  -H "Authorization: Bearer $P2_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Hijacked Store"}')
P2_ERR=$(echo "$P2_HACK_RESP" | jq -r '.error.code // empty')
assert_eq "VALIDATION_ERROR" "$P2_ERR" "Cross-partner store mutation rejected"

# Partner 2 attempts to add product to Partner 1's store
P2_ADD_PROD=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores/$STORE_ID/products" \
  -H "Authorization: Bearer $P2_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Rogue Product", "price_cents": 100}')
P2_PROD_ERR=$(echo "$P2_ADD_PROD" | jq -r '.error.code // empty')
assert_eq "VALIDATION_ERROR" "$P2_PROD_ERR" "Cross-partner product addition rejected"

# Partner 2 attempts to delete Partner 1's store
P2_DEL_RESP=$(curl -s -o /dev/null -w "%{http_code}" -X DELETE "$BASE_URL/api/v1/partner/stores/$STORE_ID" \
  -H "Authorization: Bearer $P2_TOKEN")
assert_eq "404" "$P2_DEL_RESP" "Cross-partner store deletion rejected with 404"

# Test 13: Product and Store Deletion by Owner
echo "[Test 13] Verifying product and store deletion by owner..."
DEL_PROD_RESP=$(curl -s -X DELETE "$BASE_URL/api/v1/partner/stores/$STORE_ID/products/$PROD2_ID" \
  -H "Authorization: Bearer $PARTNER_TOKEN")
DEL_OK=$(echo "$DEL_PROD_RESP" | jq -r '.success // false')
assert_eq "true" "$DEL_OK" "Product deleted successfully by owner"

# Verify deleted product is no longer returned in public catalog
PUB_AFTER_DEL=$(curl -s "$BASE_URL/api/v1/stores/$STORE_SLUG/products")
AFTER_COUNT=$(echo "$PUB_AFTER_DEL" | jq '.data.products | length')
assert_eq "1" "$AFTER_COUNT" "Deleted product removed from public catalog"

# Test 14: Strict zero-leakage check (Rules 3, 4, 10)
echo "[Test 14] Verifying zero BigStore path or internal hostname leakage..."
LEAK_COUNT=$(curl -s "$BASE_URL/api/v1/stores/$STORE_SLUG/products" | { grep -Ei "bigstore:8080|/data/files|storage_path" || true; } | wc -l)
assert_eq "0" "$LEAK_COUNT" "Zero BigStore host or internal storage paths leaked in storefront responses"

echo "========================================================"
echo "    Phase 10 Stores Results: $PASSED passed, $FAILED failed"
echo "========================================================"

if [ "$FAILED" -gt 0 ]; then
  exit 1
fi
