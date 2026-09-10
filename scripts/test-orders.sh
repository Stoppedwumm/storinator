#!/usr/bin/env bash
# ==============================================================================
# Phase 11: Shopping Cart, Checkout & Orders Automated Test Suite
# Tests atomic wallet payments, server-side pricing, inventory decrements,
# subscriber fee exemption (0€ vs 1€), partner order management, and invoices.
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
    echo "  ✘ FAIL: $msg (Expected to contain: '$needle', Got: '$haystack')"
    FAILED=$((FAILED + 1))
    exit 1
  fi
}

echo "========================================================"
echo "      Running Phase 11 Cart, Checkout & Orders Suite    "
echo "========================================================"

# Test 1: Unauthenticated access rejected
echo "[Test 1] Verifying unauthenticated requests receive HTTP 401..."
RESP_CART=$(curl -s "$BASE_URL/api/v1/cart")
CODE_CART=$(echo "$RESP_CART" | jq -r '.error.code // empty')
assert_eq "UNAUTHORIZED" "$CODE_CART" "Unauthenticated GET /api/v1/cart rejected (401)"

RESP_ORDERS=$(curl -s "$BASE_URL/api/v1/orders")
CODE_ORDERS=$(echo "$RESP_ORDERS" | jq -r '.error.code // empty')
assert_eq "UNAUTHORIZED" "$CODE_ORDERS" "Unauthenticated GET /api/v1/orders rejected (401)"

RESP_CHECKOUT=$(curl -s -X POST "$BASE_URL/api/v1/orders/checkout" -H "Content-Type: application/json" -d '{}')
CODE_CHECKOUT=$(echo "$RESP_CHECKOUT" | jq -r '.error.code // empty')
assert_eq "UNAUTHORIZED" "$CODE_CHECKOUT" "Unauthenticated POST /api/v1/orders/checkout rejected (401)"

# Test 2: Customer role authorization
echo "[Test 2] Verifying customer is blocked from partner order management..."
CUST_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"customer","password":"CustomerPass123!"}')
CUST_TOKEN=$(echo "$CUST_LOGIN" | jq -r '.data.token // empty')
assert_contains "$CUST_TOKEN" "" "Customer successfully logged in"

CUST_PARTNER_ORDERS=$(curl -s "$BASE_URL/api/v1/partner/orders" -H "Authorization: Bearer $CUST_TOKEN")
CUST_PCODE=$(echo "$CUST_PARTNER_ORDERS" | jq -r '.error.code // empty')
assert_eq "FORBIDDEN" "$CUST_PCODE" "Customer blocked from partner orders with FORBIDDEN (403)"

# Test 3: Partner setup store and products
echo "[Test 3] Partner logs in and creates store & inventory..."
PARTNER_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"partner","password":"PartnerPass123!"}')
PARTNER_TOKEN=$(echo "$PARTNER_LOGIN" | jq -r '.data.token // empty')
assert_contains "$PARTNER_TOKEN" "" "Partner successfully logged in"

RAND_SUFFIX="ord_${RANDOM}_$(date +%s)"
STORE_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Order Depot $RAND_SUFFIX\",
    \"slug\": \"order-depot-$RAND_SUFFIX\",
    \"description\": \"Store for testing cart and checkout\",
    \"contact_email\": \"depot@example.com\"
  }")
STORE_ID=$(echo "$STORE_RESP" | jq -r '.data.store.id // empty')
STORE_SLUG=$(echo "$STORE_RESP" | jq -r '.data.store.slug // empty')
assert_contains "$STORE_ID" "sto_" "Store created successfully ($STORE_ID)"

# Enable invoice retention for partner
curl -s -X POST "$BASE_URL/api/v1/partner/settings/invoice-retention" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"enabled": true}' > /dev/null

# Create Product 1: 25.00 € (2500 cents), 10 stock
PROD1_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores/$STORE_ID/products" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Mechanical Keyboard Pro",
    "description": "Ergonomic tactile switches",
    "price_cents": 2500,
    "inventory": 10
  }')
PROD1_ID=$(echo "$PROD1_RESP" | jq -r '.data.product.id // empty')
PROD1_SLUG=$(echo "$PROD1_RESP" | jq -r '.data.product.slug // empty')
assert_contains "$PROD1_ID" "prd_" "Product 1 created (25.00 €, 10 in stock)"

# Create Product 2: 15.50 € (1550 cents), 2 stock
PROD2_RESP=$(curl -s -X POST "$BASE_URL/api/v1/partner/stores/$STORE_ID/products" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Coiled USB-C Cable",
    "description": "Custom braided cable",
    "price_cents": 1550,
    "inventory": 2
  }')
PROD2_ID=$(echo "$PROD2_RESP" | jq -r '.data.product.id // empty')
PROD2_SLUG=$(echo "$PROD2_RESP" | jq -r '.data.product.slug // empty')
assert_contains "$PROD2_ID" "prd_" "Product 2 created (15.50 €, 2 in stock)"

# Test 4: Persistent Shopping Cart Management
echo "[Test 4] Customer manages shopping cart..."
# Clear cart first for clean state
curl -s -X DELETE "$BASE_URL/api/v1/cart" -H "Authorization: Bearer $CUST_TOKEN" > /dev/null

# Add 2 of Product 1
CART_ADD1=$(curl -s -X POST "$BASE_URL/api/v1/cart/items" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"store_id\": \"$STORE_ID\", \"product_id\": \"$PROD1_ID\", \"quantity\": 2}")
CART_COUNT1=$(echo "$CART_ADD1" | jq -r '.data.items_count')
assert_eq "1" "$CART_COUNT1" "1 item type in cart"

# Add 1 of Product 2
CART_ADD2=$(curl -s -X POST "$BASE_URL/api/v1/cart/items" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"store_id\": \"$STORE_ID\", \"product_id\": \"$PROD2_ID\", \"quantity\": 1}")
CART_COUNT2=$(echo "$CART_ADD2" | jq -r '.data.items_count')
assert_eq "2" "$CART_COUNT2" "2 item types in cart"

# Query cart: subtotal = 2*2500 + 1550 = 6550 cents
CART_QUERY=$(curl -s "$BASE_URL/api/v1/cart" -H "Authorization: Bearer $CUST_TOKEN")
SUBTOTAL=$(echo "$CART_QUERY" | jq -r '.data.subtotal_cents')
assert_eq "6550" "$SUBTOTAL" "Cart subtotal correctly calculated (65.50 €)"

# Non-subscriber platform fee check (1.00 € / 100 cents)
# Make sure customer is not subscribed currently for this test
PLATFORM_FEE=$(echo "$CART_QUERY" | jq -r '.data.platform_fee_cents')
IS_EXEMPT=$(echo "$CART_QUERY" | jq -r '.data.is_subscriber_fee_exempt')
if [ "$IS_EXEMPT" == "false" ]; then
  assert_eq "100" "$PLATFORM_FEE" "Non-subscriber receives 1.00 € (100 cents) platform fee"
else
  assert_eq "0" "$PLATFORM_FEE" "Active subscriber is fee exempt (0 cents)"
fi

# Update quantity of Product 1 to 1
ITEM1_ID=$(echo "$CART_QUERY" | jq -r ".data.items[] | select(.product_id == \"$PROD1_ID\") | .id")
CART_UPDATE=$(curl -s -X PATCH "$BASE_URL/api/v1/cart/items/$ITEM1_ID" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"quantity": 1}')
NEW_SUBTOTAL=$(echo "$CART_UPDATE" | jq -r '.data.subtotal_cents')
# 1*2500 + 1*1550 = 4050 cents
assert_eq "4050" "$NEW_SUBTOTAL" "Cart subtotal updated to 40.50 € after quantity adjustment"

# Test 5: Checkout Input Validation
echo "[Test 5] Verifying checkout input validation..."
# Missing store_id
VAL_NO_STORE=$(curl -s -X POST "$BASE_URL/api/v1/orders/checkout" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"items": [{"product_id": "prd_dummy", "quantity": 1}]}')
CODE_NO_STORE=$(echo "$VAL_NO_STORE" | jq -r '.error.code // empty')
assert_eq "VALIDATION_ERROR" "$CODE_NO_STORE" "Missing store_id rejected with VALIDATION_ERROR (422)"

# Empty items
VAL_NO_ITEMS=$(curl -s -X POST "$BASE_URL/api/v1/orders/checkout" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"store_id\": \"$STORE_ID\", \"items\": []}")
CODE_NO_ITEMS=$(echo "$VAL_NO_ITEMS" | jq -r '.error.code // empty')
assert_eq "VALIDATION_ERROR" "$CODE_NO_ITEMS" "Empty items rejected with VALIDATION_ERROR (422)"

# Exceeding inventory: Product 2 only has 2 units; request 5 units
VAL_OVERSTOCK=$(curl -s -X POST "$BASE_URL/api/v1/orders/checkout" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"store_id\": \"$STORE_ID\",
    \"items\": [{\"product_id\": \"$PROD2_ID\", \"quantity\": 5}]
  }")
MSG_OVERSTOCK=$(echo "$VAL_OVERSTOCK" | jq -r '.error.message // empty')
assert_contains "$MSG_OVERSTOCK" "out of stock" "Exceeding inventory rejected as out of stock (422)"

# Test 6: Insufficient Wallet Balance Protection (HTTP 402)
echo "[Test 6] Testing insufficient funds rejection (HTTP 402)..."
# Register a brand new broke customer
BROKE_USER="broke_user_${RANDOM}"
curl -s -X POST "$BASE_URL/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{
    \"username\": \"$BROKE_USER\",
    \"email\": \"$BROKE_USER@example.com\",
    \"password\": \"BrokeUser123!\",
    \"role\": \"CUSTOMER\"
  }" > /dev/null

BROKE_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"identifier\":\"$BROKE_USER\",\"password\":\"BrokeUser123!\"}")
BROKE_TOKEN=$(echo "$BROKE_LOGIN" | jq -r '.data.token')

BROKE_CHECKOUT=$(curl -s -X POST "$BASE_URL/api/v1/orders/checkout" \
  -H "Authorization: Bearer $BROKE_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"store_id\": \"$STORE_ID\",
    \"items\": [{\"product_id\": \"$PROD1_ID\", \"quantity\": 1}]
  }")
BROKE_CODE=$(echo "$BROKE_CHECKOUT" | jq -r '.error.code // empty')
assert_eq "INSUFFICIENT_FUNDS" "$BROKE_CODE" "Broke customer checkout rejected with INSUFFICIENT_FUNDS (HTTP 402)"

# Verify zero stock was deducted
PROD1_CHECK=$(curl -s "$BASE_URL/api/v1/stores/$STORE_SLUG/products/$PROD1_SLUG")
INV_CHECK=$(echo "$PROD1_CHECK" | jq -r '.data.product.inventory')
assert_eq "10" "$INV_CHECK" "Product stock preserved at 10 after failed checkout"

# Test 7: Successful Order Checkout (Server-Side Price Recalculation & Wallet Mutation)
echo "[Test 7] Executing successful checkout with atomic wallet deduction..."
# Ensure customer has enough funds in wallet
curl -s -X POST "$BASE_URL/api/v1/partner/wallet/credit" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"customer","amount_cents":10000}' > /dev/null

CUST_WALLET_BEFORE=$(curl -s "$BASE_URL/api/v1/wallet" -H "Authorization: Bearer $CUST_TOKEN" | jq -r '.data.wallet.balance_cents')

IDEM_KEY="order_key_${RANDOM}_$(date +%s)"
# Attempting to tamper with price: passing price_cents = 1 (should be ignored by server!)
CHECKOUT_RESP=$(curl -s -X POST "$BASE_URL/api/v1/orders/checkout" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -d "{
    \"store_id\": \"$STORE_ID\",
    \"items\": [
      {\"product_id\": \"$PROD1_ID\", \"quantity\": 1, \"price_cents\": 1},
      {\"product_id\": \"$PROD2_ID\", \"quantity\": 1, \"price_cents\": 1}
    ],
    \"shipping_address\": {
      \"name\": \"John Doe\",
      \"street\": \"Alexanderplatz 1\",
      \"city\": \"Berlin\",
      \"postal_code\": \"10178\",
      \"country\": \"Germany\"
    },
    \"notes\": \"Leave at reception\"
  }")

CHECKOUT_OK=$(echo "$CHECKOUT_RESP" | jq -r '.success')
assert_eq "true" "$CHECKOUT_OK" "Checkout succeeded"

ORDER_ID=$(echo "$CHECKOUT_RESP" | jq -r '.data.id // empty')
assert_contains "$ORDER_ID" "ord_" "Valid order ID returned ($ORDER_ID)"

ORDER_NUM=$(echo "$CHECKOUT_RESP" | jq -r '.data.order_number // empty')
assert_contains "$ORDER_NUM" "ORD-" "Order number generated ($ORDER_NUM)"

# Verify server-side recalculation ignored client's fake 1-cent price
ORDER_SUBTOTAL=$(echo "$CHECKOUT_RESP" | jq -r '.data.subtotal_cents')
assert_eq "4050" "$ORDER_SUBTOTAL" "Server recalculated subtotal strictly from DB (40.50 €)"

ORDER_FEE=$(echo "$CHECKOUT_RESP" | jq -r '.data.platform_fee_cents')
ORDER_TOTAL=$(echo "$CHECKOUT_RESP" | jq -r '.data.total_cents')
EXPECTED_TOTAL=$(( 4050 + ORDER_FEE ))
assert_eq "$EXPECTED_TOTAL" "$ORDER_TOTAL" "Order total equals subtotal + platform_fee"

# Verify customer wallet balance decremented by exactly total_cents
CUST_WALLET_AFTER=$(curl -s "$BASE_URL/api/v1/wallet" -H "Authorization: Bearer $CUST_TOKEN" | jq -r '.data.wallet.balance_cents')
WALLET_DIFF=$(( CUST_WALLET_BEFORE - CUST_WALLET_AFTER ))
assert_eq "$ORDER_TOTAL" "$WALLET_DIFF" "Customer wallet balance debited by exact order total ($ORDER_TOTAL cents)"

# Verify stock decremented
PROD1_NEW_INV=$(curl -s "$BASE_URL/api/v1/stores/$STORE_SLUG/products/$PROD1_SLUG" | jq -r '.data.product.inventory')
PROD2_NEW_INV=$(curl -s "$BASE_URL/api/v1/stores/$STORE_SLUG/products/$PROD2_SLUG" | jq -r '.data.product.inventory')
assert_eq "9" "$PROD1_NEW_INV" "Product 1 inventory decremented from 10 to 9"
assert_eq "1" "$PROD2_NEW_INV" "Product 2 inventory decremented from 2 to 1"

# Verify invoice generated because partner invoice retention was enabled
INVOICE_ID=$(echo "$CHECKOUT_RESP" | jq -r '.data.invoice.id // empty')
assert_contains "$INVOICE_ID" "inv_" "Invoice record created ($INVOICE_ID)"

# Test 8: Idempotency Replay Protection
echo "[Test 8] Replaying identical checkout with same Idempotency-Key..."
REPLAY_RESP=$(curl -s -X POST "$BASE_URL/api/v1/orders/checkout" \
  -H "Authorization: Bearer $CUST_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -d "{
    \"store_id\": \"$STORE_ID\",
    \"items\": [{\"product_id\": \"$PROD1_ID\", \"quantity\": 1}]
  }")

IS_REPLAY=$(echo "$REPLAY_RESP" | jq -r '.data.idempotent_replay')
REPLAY_ORDER_ID=$(echo "$REPLAY_RESP" | jq -r '.data.order.id')
assert_eq "true" "$IS_REPLAY" "Idempotent replay detected"
assert_eq "$ORDER_ID" "$REPLAY_ORDER_ID" "Replay returned original order"

# Verify wallet NOT debited a second time
WALLET_CHECK=$(curl -s "$BASE_URL/api/v1/wallet" -H "Authorization: Bearer $CUST_TOKEN" | jq -r '.data.wallet.balance_cents')
assert_eq "$CUST_WALLET_AFTER" "$WALLET_CHECK" "Wallet balance strictly untouched on replay"

# Test 9: Active Subscriber Platform Fee Exemption (0.00 € Fee)
echo "[Test 9] Verifying active subscriber 0.00 € platform fee exemption..."
# Register a subscriber user
SUB_USER="subscriber_${RANDOM}"
curl -s -X POST "$BASE_URL/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{
    \"username\": \"$SUB_USER\",
    \"email\": \"$SUB_USER@example.com\",
    \"password\": \"SubUser123!\",
    \"role\": \"CUSTOMER\"
  }" > /dev/null

SUB_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"identifier\":\"$SUB_USER\",\"password\":\"SubUser123!\"}")
SUB_TOKEN=$(echo "$SUB_LOGIN" | jq -r '.data.token')

# Give funds to subscriber
curl -s -X POST "$BASE_URL/api/v1/partner/wallet/credit" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"identifier\":\"$SUB_USER\",\"amount_cents\":10000}" > /dev/null

# Directly activate subscription in database for test
docker compose exec -T webapp sqlite3 /data/backend.sqlite "
  INSERT INTO subscriptions (id, user_id, partner_id, status, starts_at, expires_at)
  VALUES ('sub_test_${RANDOM}', (SELECT id FROM users WHERE username = '$SUB_USER'), (SELECT id FROM partners WHERE user_id = (SELECT id FROM users WHERE username = 'partner')), 'ACTIVE', datetime('now'), datetime('now', '+30 days'));
"

# Subscriber check cart
SUB_CART=$(curl -s -X POST "$BASE_URL/api/v1/cart/items" \
  -H "Authorization: Bearer $SUB_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"store_id\": \"$STORE_ID\", \"product_id\": \"$PROD1_ID\", \"quantity\": 1}")
SUB_FEE=$(echo "$SUB_CART" | jq -r '.data.platform_fee_cents')
SUB_EXEMPT=$(echo "$SUB_CART" | jq -r '.data.is_subscriber_fee_exempt')
assert_eq "0" "$SUB_FEE" "Subscriber cart platform fee is 0.00 € (0 cents)"
assert_eq "true" "$SUB_EXEMPT" "Subscriber cart marked as fee exempt"

# Subscriber checkout
SUB_CHECKOUT=$(curl -s -X POST "$BASE_URL/api/v1/orders/checkout" \
  -H "Authorization: Bearer $SUB_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"store_id\": \"$STORE_ID\",
    \"items\": [{\"product_id\": \"$PROD1_ID\", \"quantity\": 1}]
  }")
SUB_ORD_FEE=$(echo "$SUB_CHECKOUT" | jq -r '.data.platform_fee_cents')
SUB_ORD_TOTAL=$(echo "$SUB_CHECKOUT" | jq -r '.data.total_cents')
SUB_ORD_SUBTOTAL=$(echo "$SUB_CHECKOUT" | jq -r '.data.subtotal_cents')
assert_eq "0" "$SUB_ORD_FEE" "Subscriber order charged 0.00 € platform fee"
assert_eq "$SUB_ORD_SUBTOTAL" "$SUB_ORD_TOTAL" "Subscriber order total equals subtotal exactly without fee"

# Test 10: Customer Order History & Invoice Download
echo "[Test 10] Customer views order history and retrieves invoice..."
CUST_ORDERS=$(curl -s "$BASE_URL/api/v1/orders" -H "Authorization: Bearer $CUST_TOKEN")
assert_contains "$CUST_ORDERS" "$ORDER_ID" "Customer sees their newly placed order in order list"

ORDER_DETAIL=$(curl -s "$BASE_URL/api/v1/orders/$ORDER_ID" -H "Authorization: Bearer $CUST_TOKEN")
DETAIL_STATUS=$(echo "$ORDER_DETAIL" | jq -r '.data.status')
assert_eq "PAID" "$DETAIL_STATUS" "Order status is PAID"

INVOICE_RESP=$(curl -s "$BASE_URL/api/v1/orders/$ORDER_ID/invoice" -H "Authorization: Bearer $CUST_TOKEN")
INV_RETAINED=$(echo "$INVOICE_RESP" | jq -r '.data.retained')
assert_eq "true" "$INV_RETAINED" "Invoice snapshot is retained"

INV_STORE_NAME=$(echo "$INVOICE_RESP" | jq -r '.data.invoice.seller.store_name')
assert_contains "$INV_STORE_NAME" "Order Depot" "Invoice seller matches store name"

# Test 11: Cross-Customer Isolation
echo "[Test 11] Verifying customer cannot view another customer's order..."
OTHER_ACCESS=$(curl -s "$BASE_URL/api/v1/orders/$ORDER_ID" -H "Authorization: Bearer $SUB_TOKEN")
OTHER_CODE=$(echo "$OTHER_ACCESS" | jq -r '.error.code // empty')
assert_eq "FORBIDDEN" "$OTHER_CODE" "Unauthorized customer blocked with FORBIDDEN (403)"

OTHER_INV=$(curl -s "$BASE_URL/api/v1/orders/$ORDER_ID/invoice" -H "Authorization: Bearer $SUB_TOKEN")
OTHER_INV_CODE=$(echo "$OTHER_INV" | jq -r '.error.code // empty')
assert_eq "FORBIDDEN" "$OTHER_INV_CODE" "Unauthorized customer blocked from invoice (403)"

# Test 12: Partner Order Management & Fulfillment
echo "[Test 12] Partner views orders and fulfills order..."
PARTNER_ORDERS=$(curl -s "$BASE_URL/api/v1/partner/orders?store_id=$STORE_ID" \
  -H "Authorization: Bearer $PARTNER_TOKEN")
assert_contains "$PARTNER_ORDERS" "$ORDER_ID" "Partner sees order in store order list"

# Partner fulfills the order
FULFILL_RESP=$(curl -s -X PATCH "$BASE_URL/api/v1/partner/orders/$ORDER_ID/fulfill" \
  -H "Authorization: Bearer $PARTNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"notes": "Dispatched via DHL Tracking #DHL123456"}')
FULFILL_STATUS=$(echo "$FULFILL_RESP" | jq -r '.data.status')
assert_eq "COMPLETED" "$FULFILL_STATUS" "Partner successfully fulfilled order (status: COMPLETED)"

# Customer verifies order status is COMPLETED
CUST_CHECK=$(curl -s "$BASE_URL/api/v1/orders/$ORDER_ID" -H "Authorization: Bearer $CUST_TOKEN")
CUST_STATUS=$(echo "$CUST_CHECK" | jq -r '.data.status')
assert_eq "COMPLETED" "$CUST_STATUS" "Customer sees updated COMPLETED order status"

# Test 13: Cross-Partner Isolation
echo "[Test 13] Verifying other partner cannot access or fulfill order..."
# Register a second partner
PARTNER2_USER="partner2_${RANDOM}"
curl -s -X POST "$BASE_URL/api/v1/auth/register" \
  -H "Content-Type: application/json" \
  -d "{
    \"username\": \"$PARTNER2_USER\",
    \"email\": \"$PARTNER2_USER@example.com\",
    \"password\": \"PartnerPass123!\",
    \"role\": \"PARTNER\"
  }" > /dev/null

P2_LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"identifier\":\"$PARTNER2_USER\",\"password\":\"PartnerPass123!\"}")
P2_TOKEN=$(echo "$P2_LOGIN" | jq -r '.data.token')

P2_ORDERS=$(curl -s "$BASE_URL/api/v1/partner/orders" -H "Authorization: Bearer $P2_TOKEN")
P2_COUNT=$(echo "$P2_ORDERS" | jq -r '.data.orders | length')
assert_eq "0" "$P2_COUNT" "Partner 2 sees zero orders from Partner 1"

P2_MUTATE=$(curl -s -X PATCH "$BASE_URL/api/v1/partner/orders/$ORDER_ID/fulfill" \
  -H "Authorization: Bearer $P2_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"notes": "Tampered fulfill attempt"}')
P2_CODE=$(echo "$P2_MUTATE" | jq -r '.error.code // empty')
assert_eq "FORBIDDEN" "$P2_CODE" "Partner 2 blocked from fulfilling Partner 1's order with FORBIDDEN (403)"

echo "========================================================"
echo "    Phase 11 Cart & Orders Results: $PASSED passed, $FAILED failed"
echo "========================================================"
