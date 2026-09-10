<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;
use InvalidArgumentException;
use PDO;

class OrderService
{
    private PDO $pdo;
    private SubscriptionService $subscriptionService;

    public function __construct(?PDO $pdo = null, ?SubscriptionService $subscriptionService = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->subscriptionService = $subscriptionService ?? new SubscriptionService();
    }

    /**
     * Format minor integer cents to EUR currency string (e.g. 18999 -> "189.99 €")
     */
    public static function formatCents(int $cents, string $currency = 'EUR'): string
    {
        $symbol = $currency === 'EUR' ? '€' : $currency;
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return sprintf('%s%s %s', $sign, number_format($abs / 100, 2, '.', ''), $symbol);
    }

    /**
     * Process checkout and order placement atomically
     */
    public function checkout(string $userId, array $data, ?string $idempotencyKey = null): array
    {
        $storeId = $data['store_id'] ?? null;
        if (!$storeId || !is_string($storeId)) {
            throw new InvalidArgumentException('Store ID is required.');
        }

        $items = $data['items'] ?? null;
        if (empty($items) || !is_array($items)) {
            throw new InvalidArgumentException('Order must contain at least one item.');
        }

        $shippingAddress = $data['shipping_address'] ?? null;
        $notes = $data['notes'] ?? null;

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            // 1. Idempotency replay check
            if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
                $checkKey = trim($idempotencyKey);
                $stmtKey = $this->pdo->prepare('SELECT id FROM orders WHERE user_id = :uid AND idempotency_key = :ikey LIMIT 1');
                $stmtKey->execute([':uid' => $userId, ':ikey' => $checkKey]);
                $existingOrderId = $stmtKey->fetchColumn();
                $stmtKey->closeCursor();

                if ($existingOrderId) {
                    $this->pdo->commit();
                    $existingOrder = $this->getOrderInternal((string)$existingOrderId);
                    return [
                        'idempotent_replay' => true,
                        'order' => $existingOrder,
                    ];
                }
            }

            // 2. Fetch and validate store
            $stmtStore = $this->pdo->prepare('SELECT * FROM stores WHERE id = :sid LIMIT 1');
            $stmtStore->execute([':sid' => $storeId]);
            $store = $stmtStore->fetch(PDO::FETCH_ASSOC);
            $stmtStore->closeCursor();

            if (!$store || $store['status'] !== 'ACTIVE') {
                throw new Exception('Store not found or inactive.', 404);
            }

            // Fetch partner record
            $stmtPartner = $this->pdo->prepare('SELECT * FROM partners WHERE id = :pid LIMIT 1');
            $stmtPartner->execute([':pid' => $store['partner_id']]);
            $partner = $stmtPartner->fetch(PDO::FETCH_ASSOC);
            $stmtPartner->closeCursor();

            if (!$partner) {
                throw new Exception('Store partner record not found.', 404);
            }

            // 3. Validate items and inventory, compute server-side prices (Rule 11)
            $orderItemsToCreate = [];
            $subtotalCents = 0;

            foreach ($items as $idx => $item) {
                $productId = $item['product_id'] ?? null;
                $quantity = (int)($item['quantity'] ?? 0);
                $variant = $item['variant'] ?? null;

                if (!$productId || !is_string($productId)) {
                    throw new InvalidArgumentException("Item at index {$idx} is missing a valid product_id.");
                }
                if ($quantity <= 0) {
                    throw new InvalidArgumentException("Item at index {$idx} has invalid quantity (must be at least 1).");
                }

                // Query product server-side
                $stmtProd = $this->pdo->prepare('
                    SELECT id, store_id, name, sku, price_cents, inventory, status
                    FROM products
                    WHERE id = :pid AND store_id = :sid
                    LIMIT 1
                ');
                $stmtProd->execute([':pid' => $productId, ':sid' => $storeId]);
                $product = $stmtProd->fetch(PDO::FETCH_ASSOC);
                $stmtProd->closeCursor();

                if (!$product) {
                    throw new Exception("Product '{$productId}' not found in store.", 422);
                }
                if ($product['status'] !== 'ACTIVE') {
                    throw new Exception("Product '{$product['name']}' is not available for purchase.", 422);
                }
                if ((int)$product['inventory'] < $quantity) {
                    throw new Exception(
                        "Product '{$product['name']}' is out of stock (requested: {$quantity}, available: {$product['inventory']}).",
                        422
                    );
                }

                $unitPriceCents = (int)$product['price_cents'];
                $itemTotalCents = $unitPriceCents * $quantity;
                $subtotalCents += $itemTotalCents;

                $orderItemsToCreate[] = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'sku' => $product['sku'] ?? 'SKU-' . substr($product['id'], 4, 8),
                    'unit_price_cents' => $unitPriceCents,
                    'quantity' => $quantity,
                    'total_cents' => $itemTotalCents,
                    'variant_json' => !empty($variant) ? json_encode($variant) : null,
                ];
            }

            // 4. Calculate Platform Fee
            // Active subscribers: 0.00 € (0 cents); non-subscribers: 1.00 € (100 cents)
            $isSubscriber = $this->subscriptionService->hasActiveSubscription($userId);
            $platformFeeCents = $isSubscriber ? 0 : 100;
            $totalCents = $subtotalCents + $platformFeeCents;

            // 5. Atomic Wallet Check & Deduction
            $stmtWallet = $this->pdo->prepare('SELECT id, balance_cents FROM wallets WHERE user_id = :uid LIMIT 1');
            $stmtWallet->execute([':uid' => $userId]);
            $wallet = $stmtWallet->fetch(PDO::FETCH_ASSOC);
            $stmtWallet->closeCursor();

            if (!$wallet) {
                throw new Exception(
                    sprintf(
                        'Insufficient funds. Current balance: 0.00 €, required: %s.',
                        self::formatCents($totalCents)
                    ),
                    402
                );
            }

            $currentBalance = (int)$wallet['balance_cents'];
            if ($currentBalance < $totalCents) {
                throw new Exception(
                    sprintf(
                        'Insufficient funds. Current balance: %s, required: %s.',
                        self::formatCents($currentBalance),
                        self::formatCents($totalCents)
                    ),
                    402
                );
            }

            $updateWallet = $this->pdo->prepare('
                UPDATE wallets
                SET balance_cents = balance_cents - :total, updated_at = datetime("now")
                WHERE id = :wid AND balance_cents >= :total
                RETURNING balance_cents
            ');
            $updateWallet->execute([
                ':total' => $totalCents,
                ':wid' => $wallet['id'],
            ]);
            $newBalance = $updateWallet->fetchColumn();
            $updateWallet->closeCursor();

            if ($newBalance === false) {
                throw new Exception('Insufficient funds or balance mutation conflict.', 402);
            }
            $newBalance = (int)$newBalance;

            // Generate Order ID & Order Number
            $orderId = 'ord_' . bin2hex(random_bytes(12));
            $orderNumber = 'ORD-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('Ymd');
            $now = date('Y-m-d H:i:s');

            // Record wallet transaction ledger entry (Rule 7)
            $wtxId = 'wtx_' . bin2hex(random_bytes(12));
            $desc = sprintf(
                'Order #%s at %s (%s subtotal%s)',
                $orderNumber,
                $store['name'],
                self::formatCents($subtotalCents),
                $platformFeeCents > 0 ? ' + ' . self::formatCents($platformFeeCents) . ' platform fee' : ' (subscriber fee exempt)'
            );

            $insertWtx = $this->pdo->prepare('
                INSERT INTO wallet_transactions (
                    id, wallet_id, amount_cents, balance_after_cents,
                    transaction_type, reference_type, reference_id,
                    description, idempotency_key, created_at
                ) VALUES (
                    :id, :wid, :amount, :bal_after,
                    :type, :ref_type, :ref_id,
                    :desc, :idempotency, :created_at
                )
            ');
            $insertWtx->execute([
                ':id' => $wtxId,
                ':wid' => $wallet['id'],
                ':amount' => -$totalCents,
                ':bal_after' => $newBalance,
                ':type' => 'STORE_PURCHASE',
                ':ref_type' => 'order',
                ':ref_id' => $orderId,
                ':desc' => $desc,
                ':idempotency' => $idempotencyKey ? 'order_wtx_' . trim($idempotencyKey) : null,
                ':created_at' => $now,
            ]);
            $insertWtx->closeCursor();

            // 6. Atomically Decrement Product Stock Inventory
            foreach ($orderItemsToCreate as $itemData) {
                $updateStock = $this->pdo->prepare('
                    UPDATE products
                    SET inventory = inventory - :qty, updated_at = datetime("now")
                    WHERE id = :pid AND inventory >= :qty
                    RETURNING inventory
                ');
                $updateStock->execute([
                    ':qty' => $itemData['quantity'],
                    ':pid' => $itemData['product_id'],
                ]);
                $remainingStock = $updateStock->fetchColumn();
                $updateStock->closeCursor();

                if ($remainingStock === false) {
                    throw new Exception("Product '{$itemData['product_name']}' stock exhausted during checkout.", 422);
                }
            }

            // 7. Insert Order Record
            $shippingJson = !empty($shippingAddress) ? json_encode($shippingAddress) : null;
            $insertOrder = $this->pdo->prepare('
                INSERT INTO orders (
                    id, order_number, user_id, store_id, partner_id,
                    subtotal_cents, platform_fee_cents, total_cents, currency,
                    status, shipping_address_json, notes, idempotency_key,
                    created_at, updated_at
                ) VALUES (
                    :id, :ord_num, :uid, :sid, :pid,
                    :subtotal, :fee, :total, "EUR",
                    "PAID", :shipping, :notes, :ikey,
                    :created_at, :updated_at
                )
            ');
            $insertOrder->execute([
                ':id' => $orderId,
                ':ord_num' => $orderNumber,
                ':uid' => $userId,
                ':sid' => $storeId,
                ':pid' => $store['partner_id'],
                ':subtotal' => $subtotalCents,
                ':fee' => $platformFeeCents,
                ':total' => $totalCents,
                ':shipping' => $shippingJson,
                ':notes' => $notes ?? null,
                ':ikey' => $idempotencyKey ? trim($idempotencyKey) : null,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $insertOrder->closeCursor();

            // 8. Insert Order Items
            $insertItem = $this->pdo->prepare('
                INSERT INTO order_items (
                    id, order_id, product_id, product_name_snapshot,
                    sku, unit_price_cents, quantity, total_cents, variant_json
                ) VALUES (
                    :id, :oid, :pid, :name,
                    :sku, :price, :qty, :total, :variant
                )
            ');
            foreach ($orderItemsToCreate as $itemData) {
                $oriId = 'ori_' . bin2hex(random_bytes(12));
                $insertItem->execute([
                    ':id' => $oriId,
                    ':oid' => $orderId,
                    ':pid' => $itemData['product_id'],
                    ':name' => $itemData['product_name'],
                    ':sku' => $itemData['sku'],
                    ':price' => $itemData['unit_price_cents'],
                    ':qty' => $itemData['quantity'],
                    ':total' => $itemData['total_cents'],
                    ':variant' => $itemData['variant_json'],
                ]);
            }
            $insertItem->closeCursor();

            // 9. Order Event Log
            $oevId = 'oev_' . bin2hex(random_bytes(12));
            $insertEvent = $this->pdo->prepare('
                INSERT INTO order_events (id, order_id, actor_id, event_type, details_json, created_at)
                VALUES (:id, :oid, :actor, "ORDER_PLACED", :details, :created_at)
            ');
            $insertEvent->execute([
                ':id' => $oevId,
                ':oid' => $orderId,
                ':actor' => $userId,
                ':details' => json_encode([
                    'total_cents' => $totalCents,
                    'subtotal_cents' => $subtotalCents,
                    'platform_fee_cents' => $platformFeeCents,
                    'status' => 'PAID',
                ]),
                ':created_at' => $now,
            ]);
            $insertEvent->closeCursor();

            // 10. Invoice Generation (Spec Section 10: Invoice retention setting)
            $invoiceRecord = null;
            if (!empty($partner['invoice_retention_enabled'])) {
                $invId = 'inv_' . bin2hex(random_bytes(12));
                $invNumber = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

                $stmtUser = $this->pdo->prepare('SELECT id, username, email FROM users WHERE id = :uid LIMIT 1');
                $stmtUser->execute([':uid' => $userId]);
                $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: ['id' => $userId, 'username' => 'customer', 'email' => ''];
                $stmtUser->closeCursor();

                $invoiceData = [
                    'invoice_id' => $invId,
                    'invoice_number' => $invNumber,
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'issued_at' => $now,
                    'seller' => [
                        'company' => $partner['company_name'] ?? $store['name'],
                        'store_name' => $store['name'],
                        'contact_email' => $store['contact_email'] ?? $partner['contact_email'] ?? '',
                    ],
                    'buyer' => [
                        'user_id' => $userRow['id'],
                        'username' => $userRow['username'],
                        'email' => $userRow['email'],
                        'shipping_address' => $shippingAddress ?? null,
                    ],
                    'items' => array_map(function ($it) {
                        return [
                            'product_name' => $it['product_name'],
                            'sku' => $it['sku'],
                            'unit_price_cents' => $it['unit_price_cents'],
                            'formatted_unit_price' => OrderService::formatCents($it['unit_price_cents']),
                            'quantity' => $it['quantity'],
                            'total_cents' => $it['total_cents'],
                            'formatted_total' => OrderService::formatCents($it['total_cents']),
                        ];
                    }, $orderItemsToCreate),
                    'subtotal_cents' => $subtotalCents,
                    'formatted_subtotal' => OrderService::formatCents($subtotalCents),
                    'platform_fee_cents' => $platformFeeCents,
                    'formatted_platform_fee' => OrderService::formatCents($platformFeeCents),
                    'is_subscriber_fee_exempt' => $isSubscriber,
                    'total_cents' => $totalCents,
                    'formatted_total' => OrderService::formatCents($totalCents),
                    'currency' => 'EUR',
                    'payment_method' => 'WALLET_BALANCE',
                    'status' => 'PAID',
                ];

                $insertInv = $this->pdo->prepare('
                    INSERT INTO invoices (id, order_id, partner_id, user_id, invoice_number, content_json, created_at)
                    VALUES (:id, :oid, :pid, :uid, :num, :content, :created_at)
                ');
                $insertInv->execute([
                    ':id' => $invId,
                    ':oid' => $orderId,
                    ':pid' => $store['partner_id'],
                    ':uid' => $userId,
                    ':num' => $invNumber,
                    ':content' => json_encode($invoiceData),
                    ':created_at' => $now,
                ]);
                $insertInv->closeCursor();

                $invoiceRecord = [
                    'id' => $invId,
                    'invoice_number' => $invNumber,
                    'created_at' => $now,
                ];
            }

            // 11. Clear matching cart items
            $deleteCart = $this->pdo->prepare('DELETE FROM cart_items WHERE user_id = :uid AND store_id = :sid');
            $deleteCart->execute([':uid' => $userId, ':sid' => $storeId]);
            $deleteCart->closeCursor();

            // 12. Audit log
            $this->recordAudit($userId, 'ORDER_PLACED', 'orders', $orderId, [
                'order_number' => $orderNumber,
                'store_id' => $storeId,
                'subtotal_cents' => $subtotalCents,
                'platform_fee_cents' => $platformFeeCents,
                'total_cents' => $totalCents,
                'items_count' => count($orderItemsToCreate),
            ]);

            $this->pdo->commit();

            return [
                'id' => $orderId,
                'order_number' => $orderNumber,
                'store_id' => $storeId,
                'store_name' => $store['name'],
                'partner_id' => $store['partner_id'],
                'subtotal_cents' => $subtotalCents,
                'formatted_subtotal' => self::formatCents($subtotalCents),
                'platform_fee_cents' => $platformFeeCents,
                'formatted_platform_fee' => self::formatCents($platformFeeCents),
                'is_subscriber_fee_exempt' => $isSubscriber,
                'total_cents' => $totalCents,
                'formatted_total' => self::formatCents($totalCents),
                'status' => 'PAID',
                'items_count' => count($orderItemsToCreate),
                'items' => $orderItemsToCreate,
                'shipping_address' => $shippingAddress ?? null,
                'notes' => $notes ?? null,
                'invoice' => $invoiceRecord,
                'new_wallet_balance_cents' => $newBalance,
                'formatted_new_wallet_balance' => self::formatCents($newBalance),
                'created_at' => $now,
            ];

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get single order by ID with authorization verification
     */
    public function getOrder(string $orderId, string $userId, array $userRoles): array
    {
        $order = $this->getOrderInternal($orderId);
        if (!$order) {
            throw new Exception('Order not found.', 404);
        }

        // Authorization check (Rule 13)
        $isAdmin = in_array('ADMIN', $userRoles, true);
        $isOwner = ($order['user_id'] === $userId);

        if (!$isAdmin && !$isOwner) {
            // Check if user is the partner of the store
            $partnerStmt = $this->pdo->prepare('SELECT id FROM partners WHERE user_id = :uid LIMIT 1');
            $partnerStmt->execute([':uid' => $userId]);
            $partnerId = $partnerStmt->fetchColumn();
            $partnerStmt->closeCursor();

            if (!$partnerId || $partnerId !== $order['partner_id']) {
                throw new Exception('You do not have permission to view this order.', 403);
            }
        }

        return $order;
    }

    /**
     * Internal order retrieval with item breakdown and invoice status
     */
    public function getOrderInternal(string $orderId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT o.*, s.name as store_name, s.slug as store_slug, p.company_name as partner_company,
                   u.username as customer_username, u.email as customer_email
            FROM orders o
            JOIN stores s ON o.store_id = s.id
            JOIN partners p ON o.partner_id = p.id
            JOIN users u ON o.user_id = u.id
            WHERE o.id = :oid
            LIMIT 1
        ');
        $stmt->execute([':oid' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$order) {
            return null;
        }

        // Fetch items
        $itemsStmt = $this->pdo->prepare('
            SELECT oi.*, p.slug as product_slug, p.images_json
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = :oid
        ');
        $itemsStmt->execute([':oid' => $orderId]);
        $rawItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $itemsStmt->closeCursor();

        $items = array_map(function ($row) {
            $images = !empty($row['images_json']) ? json_decode($row['images_json'], true) : [];
            $variant = !empty($row['variant_json']) ? json_decode($row['variant_json'], true) : null;
            return [
                'id' => $row['id'],
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name_snapshot'],
                'product_slug' => $row['product_slug'] ?? '',
                'sku' => $row['sku'],
                'unit_price_cents' => (int)$row['unit_price_cents'],
                'formatted_unit_price' => self::formatCents((int)$row['unit_price_cents']),
                'quantity' => (int)$row['quantity'],
                'total_cents' => (int)$row['total_cents'],
                'formatted_total' => self::formatCents((int)$row['total_cents']),
                'variant' => $variant,
                'images' => $images,
            ];
        }, $rawItems);

        // Fetch invoice info if retained
        $invStmt = $this->pdo->prepare('SELECT id, invoice_number, created_at FROM invoices WHERE order_id = :oid LIMIT 1');
        $invStmt->execute([':oid' => $orderId]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
        $invStmt->closeCursor();

        // Fetch events
        $eventStmt = $this->pdo->prepare('SELECT event_type, details_json, created_at FROM order_events WHERE order_id = :oid ORDER BY created_at ASC');
        $eventStmt->execute([':oid' => $orderId]);
        $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
        $eventStmt->closeCursor();

        $shipping = !empty($order['shipping_address_json']) ? json_decode($order['shipping_address_json'], true) : null;

        return [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'user_id' => $order['user_id'],
            'customer_username' => $order['customer_username'],
            'customer_email' => $order['customer_email'],
            'store_id' => $order['store_id'],
            'store_name' => $order['store_name'],
            'store_slug' => $order['store_slug'],
            'partner_id' => $order['partner_id'],
            'partner_company' => $order['partner_company'],
            'subtotal_cents' => (int)$order['subtotal_cents'],
            'formatted_subtotal' => self::formatCents((int)$order['subtotal_cents']),
            'platform_fee_cents' => (int)$order['platform_fee_cents'],
            'formatted_platform_fee' => self::formatCents((int)$order['platform_fee_cents']),
            'is_subscriber_fee_exempt' => ((int)$order['platform_fee_cents'] === 0),
            'total_cents' => (int)$order['total_cents'],
            'formatted_total' => self::formatCents((int)$order['total_cents']),
            'currency' => $order['currency'] ?? 'EUR',
            'status' => $order['status'],
            'shipping_address' => $shipping,
            'notes' => $order['notes'],
            'items' => $items,
            'items_count' => count($items),
            'invoice' => $invoice ?: null,
            'events' => array_map(function ($ev) {
                return [
                    'event' => $ev['event_type'],
                    'details' => !empty($ev['details_json']) ? json_decode($ev['details_json'], true) : null,
                    'timestamp' => $ev['created_at'],
                ];
            }, $events),
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
        ];
    }

    /**
     * List customer's own orders
     */
    public function listCustomerOrders(string $userId, int $limit = 20, int $offset = 0, ?string $status = null): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where = ['o.user_id = :uid'];
        $params = [':uid' => $userId];

        if ($status !== null && trim($status) !== '') {
            $where[] = 'o.status = :status';
            $params[':status'] = strtoupper(trim($status));
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM orders o {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $countStmt->closeCursor();

        $stmt = $this->pdo->prepare("
            SELECT o.*, s.name as store_name, s.slug as store_slug,
                   (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count,
                   (SELECT invoice_number FROM invoices WHERE order_id = o.id LIMIT 1) as invoice_number
            FROM orders o
            JOIN stores s ON o.store_id = s.id
            {$whereClause}
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $orders = array_map(function ($row) {
            return [
                'id' => $row['id'],
                'order_number' => $row['order_number'],
                'store_id' => $row['store_id'],
                'store_name' => $row['store_name'],
                'store_slug' => $row['store_slug'],
                'subtotal_cents' => (int)$row['subtotal_cents'],
                'formatted_subtotal' => self::formatCents((int)$row['subtotal_cents']),
                'platform_fee_cents' => (int)$row['platform_fee_cents'],
                'formatted_platform_fee' => self::formatCents((int)$row['platform_fee_cents']),
                'total_cents' => (int)$row['total_cents'],
                'formatted_total' => self::formatCents((int)$row['total_cents']),
                'status' => $row['status'],
                'items_count' => (int)$row['items_count'],
                'has_invoice' => !empty($row['invoice_number']),
                'invoice_number' => $row['invoice_number'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        return [
            'orders' => $orders,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total,
            ],
        ];
    }

    /**
     * List orders for a partner across all stores or for a specific store
     */
    public function listPartnerOrders(
        string $partnerUserId,
        array $userRoles,
        ?string $storeId = null,
        ?string $status = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $isAdmin = in_array('ADMIN', $userRoles, true);
        $where = [];
        $params = [];

        if (!$isAdmin) {
            $pStmt = $this->pdo->prepare('SELECT id FROM partners WHERE user_id = :uid LIMIT 1');
            $pStmt->execute([':uid' => $partnerUserId]);
            $partnerId = $pStmt->fetchColumn();
            $pStmt->closeCursor();

            if (!$partnerId) {
                return [
                    'orders' => [],
                    'pagination' => ['total' => 0, 'limit' => $limit, 'offset' => $offset, 'has_more' => false],
                    'summary' => ['total_orders' => 0, 'total_revenue_cents' => 0, 'formatted_revenue' => '0.00 €'],
                ];
            }

            $where[] = 'o.partner_id = :pid';
            $params[':pid'] = $partnerId;
        }

        if ($storeId !== null && trim($storeId) !== '') {
            $where[] = 'o.store_id = :sid';
            $params[':sid'] = trim($storeId);
        }

        if ($status !== null && trim($status) !== '') {
            $where[] = 'o.status = :status';
            $params[':status'] = strtoupper(trim($status));
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Metrics
        $metricStmt = $this->pdo->prepare("
            SELECT COUNT(*) as total_orders,
                   COALESCE(SUM(o.subtotal_cents), 0) as total_revenue_cents,
                   COALESCE(SUM(CASE WHEN o.status = 'PAID' THEN 1 ELSE 0 END), 0) as pending_fulfillment
            FROM orders o
            {$whereClause}
        ");
        $metricStmt->execute($params);
        $metrics = $metricStmt->fetch(PDO::FETCH_ASSOC);
        $metricStmt->closeCursor();

        $total = (int)($metrics['total_orders'] ?? 0);
        $revenueCents = (int)($metrics['total_revenue_cents'] ?? 0);
        $pendingCount = (int)($metrics['pending_fulfillment'] ?? 0);

        // Fetch paginated
        $stmt = $this->pdo->prepare("
            SELECT o.*, s.name as store_name, u.username as customer_username,
                   (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count,
                   (SELECT invoice_number FROM invoices WHERE order_id = o.id LIMIT 1) as invoice_number
            FROM orders o
            JOIN stores s ON o.store_id = s.id
            JOIN users u ON o.user_id = u.id
            {$whereClause}
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $orders = array_map(function ($row) {
            return [
                'id' => $row['id'],
                'order_number' => $row['order_number'],
                'store_id' => $row['store_id'],
                'store_name' => $row['store_name'],
                'customer_username' => $row['customer_username'],
                'subtotal_cents' => (int)$row['subtotal_cents'],
                'formatted_subtotal' => self::formatCents((int)$row['subtotal_cents']),
                'platform_fee_cents' => (int)$row['platform_fee_cents'],
                'formatted_platform_fee' => self::formatCents((int)$row['platform_fee_cents']),
                'total_cents' => (int)$row['total_cents'],
                'formatted_total' => self::formatCents((int)$row['total_cents']),
                'status' => $row['status'],
                'items_count' => (int)$row['items_count'],
                'has_invoice' => !empty($row['invoice_number']),
                'invoice_number' => $row['invoice_number'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        return [
            'orders' => $orders,
            'summary' => [
                'total_orders' => $total,
                'total_revenue_cents' => $revenueCents,
                'formatted_revenue' => self::formatCents($revenueCents),
                'pending_fulfillment' => $pendingCount,
            ],
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total,
            ],
        ];
    }

    /**
     * Update order fulfillment status (Partner or Admin)
     */
    public function updateOrderStatus(
        string $orderId,
        string $actorUserId,
        string $newStatus,
        array $userRoles,
        ?string $notes = null
    ): array {
        $allowedStatuses = ['PAID', 'PROCESSING', 'SHIPPED', 'COMPLETED', 'CANCELLED'];
        $newStatus = strtoupper(trim($newStatus));
        if (!in_array($newStatus, $allowedStatuses, true)) {
            throw new InvalidArgumentException("Invalid order status: {$newStatus}");
        }

        $order = $this->getOrderInternal($orderId);
        if (!$order) {
            throw new Exception('Order not found.', 404);
        }

        $isAdmin = in_array('ADMIN', $userRoles, true);
        if (!$isAdmin) {
            $pStmt = $this->pdo->prepare('SELECT id FROM partners WHERE user_id = :uid LIMIT 1');
            $pStmt->execute([':uid' => $actorUserId]);
            $partnerId = $pStmt->fetchColumn();
            $pStmt->closeCursor();

            if (!$partnerId || $partnerId !== $order['partner_id']) {
                throw new Exception('You do not have permission to manage this order.', 403);
            }
        }

        $now = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('
                UPDATE orders
                SET status = :status, updated_at = :updated_at
                WHERE id = :id
            ');
            $update->execute([
                ':status' => $newStatus,
                ':updated_at' => $now,
                ':id' => $orderId,
            ]);
            $update->closeCursor();

            // Insert event log
            $oevId = 'oev_' . bin2hex(random_bytes(12));
            $eventStmt = $this->pdo->prepare('
                INSERT INTO order_events (id, order_id, actor_id, event_type, details_json, created_at)
                VALUES (:id, :oid, :actor, :type, :details, :created_at)
            ');
            $eventStmt->execute([
                ':id' => $oevId,
                ':oid' => $orderId,
                ':actor' => $actorUserId,
                ':type' => 'STATUS_CHANGED',
                ':details' => json_encode(['new_status' => $newStatus, 'notes' => $notes]),
                ':created_at' => $now,
            ]);
            $eventStmt->closeCursor();

            $this->recordAudit($actorUserId, 'ORDER_STATUS_UPDATED', 'orders', $orderId, [
                'old_status' => $order['status'],
                'new_status' => $newStatus,
                'notes' => $notes,
            ]);

            $this->pdo->commit();
            return $this->getOrderInternal($orderId);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get invoice document for an order
     */
    public function getInvoice(string $orderId, string $userId, array $userRoles): array
    {
        // First verify access to the order
        $order = $this->getOrder($orderId, $userId, $userRoles);

        $stmt = $this->pdo->prepare('SELECT * FROM invoices WHERE order_id = :oid LIMIT 1');
        $stmt->execute([':oid' => $orderId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$invoice) {
            // Return on-the-fly transaction receipt if partner retention was disabled
            return [
                'retained' => false,
                'message' => 'Permanent invoice retention is disabled by the merchant for this store. Standard transaction receipt provided.',
                'order' => $order,
            ];
        }

        $content = json_decode($invoice['content_json'], true);
        return [
            'retained' => true,
            'id' => $invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'created_at' => $invoice['created_at'],
            'invoice' => $content,
        ];
    }

    private function recordAudit(string $actorId, string $action, string $targetType, string $targetId, array $metadata): void
    {
        try {
            $logId = 'aud_' . bin2hex(random_bytes(12));
            $stmt = $this->pdo->prepare('
                INSERT INTO audit_logs (id, actor_id, action, target_type, target_id, ip_address, metadata, created_at)
                VALUES (:id, :actor_id, :action, :target_type, :target_id, :ip, :metadata, datetime("now"))
            ');
            $stmt->execute([
                ':id' => $logId,
                ':actor_id' => $actorId,
                ':action' => $action,
                ':target_type' => $targetType,
                ':target_id' => $targetId,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ':metadata' => json_encode($metadata),
            ]);
        } catch (\Throwable) {
            // Non-blocking audit record failure
        }
    }
}
