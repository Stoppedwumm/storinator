<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;
use InvalidArgumentException;
use PDO;

class CartService
{
    private PDO $pdo;
    private SubscriptionService $subscriptionService;

    public function __construct(?PDO $pdo = null, ?SubscriptionService $subscriptionService = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->subscriptionService = $subscriptionService ?? new SubscriptionService();
    }

    /**
     * Get user's cart with live pricing and platform fee calculation
     */
    public function getCart(string $userId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT c.id as cart_item_id, c.quantity, c.variant_json, c.created_at,
                   p.id as product_id, p.name as product_name, p.slug as product_slug,
                   p.sku, p.price_cents, p.inventory, p.status as product_status, p.images_json,
                   s.id as store_id, s.name as store_name, s.slug as store_slug
            FROM cart_items c
            JOIN products p ON c.product_id = p.id
            JOIN stores s ON c.store_id = s.id
            WHERE c.user_id = :uid
            ORDER BY c.created_at DESC
        ');
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $items = [];
        $subtotalCents = 0;
        $storesMap = [];

        foreach ($rows as $row) {
            $unitPriceCents = (int)$row['price_cents'];
            $qty = (int)$row['quantity'];
            $itemTotalCents = $unitPriceCents * $qty;
            $subtotalCents += $itemTotalCents;

            $images = !empty($row['images_json']) ? json_decode($row['images_json'], true) : [];
            $variant = !empty($row['variant_json']) ? json_decode($row['variant_json'], true) : null;

            $item = [
                'id' => $row['cart_item_id'],
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'product_slug' => $row['product_slug'],
                'sku' => $row['sku'],
                'store_id' => $row['store_id'],
                'store_name' => $row['store_name'],
                'store_slug' => $row['store_slug'],
                'unit_price_cents' => $unitPriceCents,
                'formatted_unit_price' => OrderService::formatCents($unitPriceCents),
                'quantity' => $qty,
                'total_cents' => $itemTotalCents,
                'formatted_total' => OrderService::formatCents($itemTotalCents),
                'available_stock' => (int)$row['inventory'],
                'in_stock' => ((int)$row['inventory'] >= $qty),
                'product_active' => ($row['product_status'] === 'ACTIVE'),
                'variant' => $variant,
                'image' => !empty($images[0]) ? $images[0] : null,
            ];

            $items[] = $item;
            $storesMap[$row['store_id']] = [
                'id' => $row['store_id'],
                'name' => $row['store_name'],
                'slug' => $row['store_slug'],
            ];
        }

        // Platform fee: 0 for active subscriber, 100 cents (1.00 €) for non-subscriber
        $isSubscriber = $this->subscriptionService->hasActiveSubscription($userId);
        $platformFeeCents = (count($items) > 0 && !$isSubscriber) ? 100 : 0;
        $totalCents = $subtotalCents + $platformFeeCents;

        return [
            'items' => $items,
            'items_count' => count($items),
            'stores' => array_values($storesMap),
            'single_store_order' => (count($storesMap) <= 1),
            'subtotal_cents' => $subtotalCents,
            'formatted_subtotal' => OrderService::formatCents($subtotalCents),
            'platform_fee_cents' => $platformFeeCents,
            'formatted_platform_fee' => OrderService::formatCents($platformFeeCents),
            'is_subscriber_fee_exempt' => $isSubscriber,
            'total_cents' => $totalCents,
            'formatted_total' => OrderService::formatCents($totalCents),
        ];
    }

    /**
     * Add item to persistent cart
     */
    public function addItem(string $userId, string $storeId, string $productId, int $quantity = 1, ?array $variant = null): array
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        // Validate product and store
        $prodStmt = $this->pdo->prepare('
            SELECT id, store_id, name, inventory, status
            FROM products
            WHERE id = :pid AND store_id = :sid
            LIMIT 1
        ');
        $prodStmt->execute([':pid' => $productId, ':sid' => $storeId]);
        $prod = $prodStmt->fetch(PDO::FETCH_ASSOC);
        $prodStmt->closeCursor();

        if (!$prod) {
            throw new Exception('Product not found in this store.', 404);
        }
        if ($prod['status'] !== 'ACTIVE') {
            throw new Exception("Product '{$prod['name']}' is not available.", 422);
        }

        $variantJson = !empty($variant) ? json_encode($variant) : null;

        // Check if item already in cart
        $checkStmt = $this->pdo->prepare('
            SELECT id, quantity
            FROM cart_items
            WHERE user_id = :uid AND product_id = :pid AND (variant_json = :vjson OR (variant_json IS NULL AND :vjson2 IS NULL))
            LIMIT 1
        ');
        $checkStmt->execute([
            ':uid' => $userId,
            ':pid' => $productId,
            ':vjson' => $variantJson,
            ':vjson2' => $variantJson,
        ]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $checkStmt->closeCursor();

        $now = date('Y-m-d H:i:s');
        if ($existing) {
            $newQty = (int)$existing['quantity'] + $quantity;
            $update = $this->pdo->prepare('
                UPDATE cart_items
                SET quantity = :qty, updated_at = :updated_at
                WHERE id = :id
            ');
            $update->execute([':qty' => $newQty, ':updated_at' => $now, ':id' => $existing['id']]);
        } else {
            $cartItemId = 'crt_' . bin2hex(random_bytes(12));
            $insert = $this->pdo->prepare('
                INSERT INTO cart_items (id, user_id, store_id, product_id, variant_json, quantity, created_at, updated_at)
                VALUES (:id, :uid, :sid, :pid, :vjson, :qty, :created_at, :updated_at)
            ');
            $insert->execute([
                ':id' => $cartItemId,
                ':uid' => $userId,
                ':sid' => $storeId,
                ':pid' => $productId,
                ':vjson' => $variantJson,
                ':qty' => $quantity,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }

        return $this->getCart($userId);
    }

    /**
     * Update cart item quantity
     */
    public function updateItem(string $userId, string $cartItemId, int $quantity): array
    {
        if ($quantity <= 0) {
            return $this->removeItem($userId, $cartItemId);
        }

        $update = $this->pdo->prepare('
            UPDATE cart_items
            SET quantity = :qty, updated_at = datetime("now")
            WHERE id = :id AND user_id = :uid
        ');
        $update->execute([':qty' => $quantity, ':id' => $cartItemId, ':uid' => $userId]);

        return $this->getCart($userId);
    }

    /**
     * Remove item from cart
     */
    public function removeItem(string $userId, string $cartItemId): array
    {
        $delete = $this->pdo->prepare('DELETE FROM cart_items WHERE id = :id AND user_id = :uid');
        $delete->execute([':id' => $cartItemId, ':uid' => $userId]);

        return $this->getCart($userId);
    }

    /**
     * Clear all cart items or items for a specific store
     */
    public function clearCart(string $userId, ?string $storeId = null): array
    {
        if ($storeId !== null) {
            $delete = $this->pdo->prepare('DELETE FROM cart_items WHERE user_id = :uid AND store_id = :sid');
            $delete->execute([':uid' => $userId, ':sid' => $storeId]);
        } else {
            $delete = $this->pdo->prepare('DELETE FROM cart_items WHERE user_id = :uid');
            $delete->execute([':uid' => $userId]);
        }

        return $this->getCart($userId);
    }
}
