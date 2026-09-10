<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;
use InvalidArgumentException;
use PDO;

class StoreService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    public static function formatCents(int $cents, string $currency = 'EUR'): string
    {
        $symbol = $currency === 'EUR' ? '€' : $currency;
        return number_format($cents / 100, 2, '.', '') . ' ' . $symbol;
    }

    public static function slugify(string $text): string
    {
        $slug = preg_replace('~[^\pL\d]+~u', '-', $text);
        $slug = iconv('utf-8', 'us-ascii//TRANSLIT', $slug);
        $slug = preg_replace('~[^-\w]+~', '', $slug);
        $slug = trim($slug, '-');
        $slug = preg_replace('~-+~', '-', $slug);
        $slug = strtolower($slug);
        return empty($slug) ? 'item-' . bin2hex(random_bytes(3)) : $slug;
    }

    public function getPartnerByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM partners WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);
        return $partner ?: null;
    }

    public function ensurePartner(string $userId): array
    {
        $partner = $this->getPartnerByUserId($userId);
        if ($partner) {
            return $partner;
        }

        // Look up user info
        $userStmt = $this->pdo->prepare('SELECT id, username FROM users WHERE id = :uid');
        $userStmt->execute([':uid' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new InvalidArgumentException('User does not exist.');
        }

        $partnerId = 'par_' . bin2hex(random_bytes(12));
        $company = ucfirst($user['username']) . ' Merchant';
        $now = date('Y-m-d H:i:s');

        $insert = $this->pdo->prepare('
            INSERT INTO partners (id, user_id, company_name, debt_cents, created_at, updated_at)
            VALUES (:id, :uid, :comp, 0, :now, :now)
        ');
        $insert->execute([
            ':id' => $partnerId,
            ':uid' => $userId,
            ':comp' => $company,
            ':now' => $now,
        ]);

        return [
            'id' => $partnerId,
            'user_id' => $userId,
            'company_name' => $company,
            'debt_cents' => 0,
        ];
    }

    // =========================================================================
    // Store Management (Spec Section 22 & 23)
    // =========================================================================

    public function createStore(string $userId, array $data, bool $isAdmin = false): array
    {
        $partner = $this->getPartnerByUserId($userId);
        if (!$partner) {
            if ($isAdmin) {
                $partner = $this->ensurePartner($userId);
            } else {
                throw new InvalidArgumentException('Only partners can create stores.');
            }
        }

        $name = trim($data['name'] ?? '');
        if (strlen($name) < 2 || strlen($name) > 100) {
            throw new InvalidArgumentException('Store name must be between 2 and 100 characters.');
        }

        $rawSlug = trim($data['slug'] ?? '');
        $slug = !empty($rawSlug) ? self::slugify($rawSlug) : self::slugify($name);

        // Check uniqueness of slug
        $chk = $this->pdo->prepare('SELECT id FROM stores WHERE slug = :slug');
        $chk->execute([':slug' => $slug]);
        if ($chk->fetchColumn()) {
            throw new InvalidArgumentException("A store with slug '{$slug}' already exists.");
        }

        $storeId = 'sto_' . bin2hex(random_bytes(12));
        $now = date('Y-m-d H:i:s');
        $themeColor = $data['theme_color'] ?? '#6366f1';
        $description = $data['description'] ?? null;
        $logoUrl = $data['logo_url'] ?? null;
        $bannerUrl = $data['banner_url'] ?? null;
        $contactEmail = $data['contact_email'] ?? null;
        $contactPhone = $data['contact_phone'] ?? null;
        $termsContent = $data['terms_content'] ?? null;
        $settingsJson = isset($data['settings']) && is_array($data['settings']) 
            ? json_encode($data['settings']) 
            : ($data['settings_json'] ?? null);

        $stmt = $this->pdo->prepare('
            INSERT INTO stores (
                id, partner_id, slug, name, description, status,
                logo_url, banner_url, theme_color, contact_email,
                contact_phone, terms_content, settings_json, created_at, updated_at
            ) VALUES (
                :id, :partner_id, :slug, :name, :description, :status,
                :logo_url, :banner_url, :theme_color, :contact_email,
                :contact_phone, :terms_content, :settings_json, :now, :now
            )
        ');

        $stmt->execute([
            ':id' => $storeId,
            ':partner_id' => $partner['id'],
            ':slug' => $slug,
            ':name' => $name,
            ':description' => $description,
            ':status' => 'ACTIVE',
            ':logo_url' => $logoUrl,
            ':banner_url' => $bannerUrl,
            ':theme_color' => $themeColor,
            ':contact_email' => $contactEmail,
            ':contact_phone' => $contactPhone,
            ':terms_content' => $termsContent,
            ':settings_json' => $settingsJson,
            ':now' => $now,
        ]);

        return $this->getStoreById($storeId);
    }

    public function getStoreById(string $storeId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT s.*, p.company_name as partner_company, u.username as partner_username
            FROM stores s
            JOIN partners p ON p.id = s.partner_id
            JOIN users u ON u.id = p.user_id
            WHERE s.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $storeId]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) {
            return null;
        }

        $store['settings'] = !empty($store['settings_json']) ? json_decode($store['settings_json'], true) : [];
        $store['categories'] = $this->listCategories($store['id']);
        return $store;
    }

    public function getStoreBySlug(string $slug, bool $activeOnly = true): ?array
    {
        $sql = '
            SELECT s.*, p.company_name as partner_company, u.username as partner_username
            FROM stores s
            JOIN partners p ON p.id = s.partner_id
            JOIN users u ON u.id = p.user_id
            WHERE s.slug = :slug
        ';
        if ($activeOnly) {
            $sql .= " AND s.status = 'ACTIVE'";
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':slug' => $slug]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) {
            return null;
        }

        $store['settings'] = !empty($store['settings_json']) ? json_decode($store['settings_json'], true) : [];
        $store['categories'] = $this->listCategories($store['id']);
        return $store;
    }

    public function listStores(?string $search = null, ?string $status = 'ACTIVE', int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($status)) {
            $where[] = 's.status = :status';
            $params[':status'] = $status;
        }

        if (!empty($search)) {
            $where[] = '(s.name LIKE :search OR s.description LIKE :search OR s.slug LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM stores s {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT s.*, p.company_name as partner_company,
                   (SELECT COUNT(*) FROM products pr WHERE pr.store_id = s.id AND pr.status = 'ACTIVE') as active_products_count
            FROM stores s
            JOIN partners p ON p.id = s.partner_id
            {$whereClause}
            ORDER BY s.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stores as &$store) {
            $store['settings'] = !empty($store['settings_json']) ? json_decode($store['settings_json'], true) : [];
            $store['active_products_count'] = (int)$store['active_products_count'];
        }

        return [
            'stores' => $stores,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + count($stores)) < $total,
            ],
        ];
    }

    public function listPartnerStores(string $userId, bool $isAdmin = false, int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!$isAdmin) {
            $partner = $this->getPartnerByUserId($userId);
            if (!$partner) {
                return [
                    'stores' => [],
                    'pagination' => ['total' => 0, 'limit' => $limit, 'offset' => $offset, 'has_more' => false],
                ];
            }
            $where[] = 's.partner_id = :pid';
            $params[':pid'] = $partner['id'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM stores s {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM products pr WHERE pr.store_id = s.id) as total_products_count,
                   (SELECT COUNT(*) FROM products pr WHERE pr.store_id = s.id AND pr.status = 'ACTIVE') as active_products_count
            FROM stores s
            {$whereClause}
            ORDER BY s.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stores as &$store) {
            $store['settings'] = !empty($store['settings_json']) ? json_decode($store['settings_json'], true) : [];
            $store['total_products_count'] = (int)$store['total_products_count'];
            $store['active_products_count'] = (int)$store['active_products_count'];
        }

        return [
            'stores' => $stores,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + count($stores)) < $total,
            ],
        ];
    }

    public function verifyStoreAccess(string $userId, string $storeId, bool $isAdmin = false): array
    {
        $store = $this->getStoreById($storeId);
        if (!$store) {
            throw new InvalidArgumentException('Store not found.');
        }

        if ($isAdmin) {
            return $store;
        }

        $partner = $this->getPartnerByUserId($userId);
        if (!$partner || $partner['id'] !== $store['partner_id']) {
            throw new InvalidArgumentException('Access denied: You do not own this store.');
        }

        return $store;
    }

    public function updateStore(string $userId, string $storeId, array $data, bool $isAdmin = false): array
    {
        $store = $this->verifyStoreAccess($userId, $storeId, $isAdmin);

        $fields = [];
        $params = [':id' => $storeId, ':now' => date('Y-m-d H:i:s')];

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (strlen($name) < 2 || strlen($name) > 100) {
                throw new InvalidArgumentException('Store name must be between 2 and 100 characters.');
            }
            $fields[] = 'name = :name';
            $params[':name'] = $name;
        }

        if (isset($data['slug'])) {
            $slug = self::slugify($data['slug']);
            if ($slug !== $store['slug']) {
                $chk = $this->pdo->prepare('SELECT id FROM stores WHERE slug = :slug AND id != :id');
                $chk->execute([':slug' => $slug, ':id' => $storeId]);
                if ($chk->fetchColumn()) {
                    throw new InvalidArgumentException("A store with slug '{$slug}' already exists.");
                }
                $fields[] = 'slug = :slug';
                $params[':slug'] = $slug;
            }
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = :desc';
            $params[':desc'] = $data['description'];
        }

        if (array_key_exists('logo_url', $data)) {
            $fields[] = 'logo_url = :logo';
            $params[':logo'] = $data['logo_url'];
        }

        if (array_key_exists('banner_url', $data)) {
            $fields[] = 'banner_url = :banner';
            $params[':banner'] = $data['banner_url'];
        }

        if (array_key_exists('theme_color', $data)) {
            $fields[] = 'theme_color = :theme';
            $params[':theme'] = $data['theme_color'];
        }

        if (array_key_exists('contact_email', $data)) {
            $fields[] = 'contact_email = :email';
            $params[':email'] = $data['contact_email'];
        }

        if (array_key_exists('contact_phone', $data)) {
            $fields[] = 'contact_phone = :phone';
            $params[':phone'] = $data['contact_phone'];
        }

        if (array_key_exists('terms_content', $data)) {
            $fields[] = 'terms_content = :terms';
            $params[':terms'] = $data['terms_content'];
        }

        if (isset($data['status'])) {
            $validStatuses = ['ACTIVE', 'INACTIVE', 'ARCHIVED'];
            if (!in_array($data['status'], $validStatuses, true)) {
                throw new InvalidArgumentException('Invalid store status.');
            }
            $fields[] = 'status = :status';
            $params[':status'] = $data['status'];
        }

        if (isset($data['settings'])) {
            $fields[] = 'settings_json = :settings';
            $params[':settings'] = json_encode($data['settings']);
        }

        if (empty($fields)) {
            return $this->getStoreById($storeId);
        }

        $fields[] = 'updated_at = :now';
        $sql = 'UPDATE stores SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->getStoreById($storeId);
    }

    public function deleteStore(string $userId, string $storeId, bool $isAdmin = false): bool
    {
        $this->verifyStoreAccess($userId, $storeId, $isAdmin);
        $stmt = $this->pdo->prepare('DELETE FROM stores WHERE id = :id');
        return $stmt->execute([':id' => $storeId]);
    }

    // =========================================================================
    // Category Management
    // =========================================================================

    public function createCategory(string $userId, string $storeId, array $data, bool $isAdmin = false): array
    {
        $this->verifyStoreAccess($userId, $storeId, $isAdmin);

        $name = trim($data['name'] ?? '');
        if (empty($name)) {
            throw new InvalidArgumentException('Category name is required.');
        }

        $slug = !empty($data['slug']) ? self::slugify($data['slug']) : self::slugify($name);
        $sortOrder = (int)($data['sort_order'] ?? 0);

        // Check uniqueness per store
        $chk = $this->pdo->prepare('SELECT id FROM store_categories WHERE store_id = :sid AND slug = :slug');
        $chk->execute([':sid' => $storeId, ':slug' => $slug]);
        if ($chk->fetchColumn()) {
            throw new InvalidArgumentException("A category with slug '{$slug}' already exists in this store.");
        }

        $catId = 'sct_' . bin2hex(random_bytes(10));
        $stmt = $this->pdo->prepare('
            INSERT INTO store_categories (id, store_id, name, slug, sort_order)
            VALUES (:id, :sid, :name, :slug, :sort_order)
        ');
        $stmt->execute([
            ':id' => $catId,
            ':sid' => $storeId,
            ':name' => $name,
            ':slug' => $slug,
            ':sort_order' => $sortOrder,
        ]);

        return [
            'id' => $catId,
            'store_id' => $storeId,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => $sortOrder,
        ];
    }

    public function listCategories(string $storeId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT sc.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = sc.id AND p.status = "ACTIVE") as product_count
            FROM store_categories sc
            WHERE sc.store_id = :sid
            ORDER BY sc.sort_order ASC, sc.name ASC
        ');
        $stmt->execute([':sid' => $storeId]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($categories as &$c) {
            $c['product_count'] = (int)$c['product_count'];
            $c['sort_order'] = (int)$c['sort_order'];
        }
        return $categories;
    }

    public function deleteCategory(string $userId, string $storeId, string $categoryId, bool $isAdmin = false): bool
    {
        $this->verifyStoreAccess($userId, $storeId, $isAdmin);
        $stmt = $this->pdo->prepare('DELETE FROM store_categories WHERE id = :id AND store_id = :sid');
        return $stmt->execute([':id' => $categoryId, ':sid' => $storeId]);
    }

    // =========================================================================
    // Product Management (Spec Section 24)
    // =========================================================================

    public function createProduct(string $userId, string $storeId, array $data, bool $isAdmin = false): array
    {
        $this->verifyStoreAccess($userId, $storeId, $isAdmin);

        $name = trim($data['name'] ?? '');
        if (strlen($name) < 2) {
            throw new InvalidArgumentException('Product name must be at least 2 characters.');
        }

        $priceCents = (int)($data['price_cents'] ?? 0);
        if ($priceCents < 0) {
            throw new InvalidArgumentException('Product price must be non-negative integer cents.');
        }

        $inventory = isset($data['inventory']) ? (int)$data['inventory'] : 0;
        if ($inventory < 0) {
            throw new InvalidArgumentException('Inventory cannot be negative.');
        }

        $slug = !empty($data['slug']) ? self::slugify($data['slug']) : self::slugify($name);

        // Check uniqueness per store
        $chk = $this->pdo->prepare('SELECT id FROM products WHERE store_id = :sid AND slug = :slug');
        $chk->execute([':sid' => $storeId, ':slug' => $slug]);
        if ($chk->fetchColumn()) {
            throw new InvalidArgumentException("A product with slug '{$slug}' already exists in this store.");
        }

        $productId = 'prd_' . bin2hex(random_bytes(12));
        $now = date('Y-m-d H:i:s');
        $currency = $data['currency'] ?? 'EUR';
        $shortDesc = $data['short_description'] ?? null;
        $description = $data['description'] ?? null;
        $sku = $data['sku'] ?? ('SKU-' . strtoupper(bin2hex(random_bytes(4))));
        $categoryId = $data['category_id'] ?? null;
        $status = $data['status'] ?? 'ACTIVE';

        $imagesJson = isset($data['images']) && is_array($data['images'])
            ? json_encode($data['images'])
            : ($data['images_json'] ?? '[]');

        $variantsJson = isset($data['variants']) && is_array($data['variants'])
            ? json_encode($data['variants'])
            : ($data['variants_json'] ?? '[]');

        $stmt = $this->pdo->prepare('
            INSERT INTO products (
                id, store_id, category_id, slug, name, short_description,
                description, price_cents, currency, inventory, sku, status,
                images_json, variants_json, created_at, updated_at
            ) VALUES (
                :id, :store_id, :cat_id, :slug, :name, :short_desc,
                :desc, :price_cents, :currency, :inventory, :sku, :status,
                :images, :variants, :now, :now
            )
        ');

        $stmt->execute([
            ':id' => $productId,
            ':store_id' => $storeId,
            ':cat_id' => $categoryId,
            ':slug' => $slug,
            ':name' => $name,
            ':short_desc' => $shortDesc,
            ':desc' => $description,
            ':price_cents' => $priceCents,
            ':currency' => $currency,
            ':inventory' => $inventory,
            ':sku' => $sku,
            ':status' => $status,
            ':images' => $imagesJson,
            ':variants' => $variantsJson,
            ':now' => $now,
        ]);

        return $this->getProduct($storeId, $productId);
    }

    public function getProduct(string $storeId, string $productId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT p.*, sc.name as category_name
            FROM products p
            LEFT JOIN store_categories sc ON sc.id = p.category_id
            WHERE p.id = :id AND p.store_id = :sid
            LIMIT 1
        ');
        $stmt->execute([':id' => $productId, ':sid' => $storeId]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            return null;
        }

        return $this->formatProductRow($prod);
    }

    public function getProductBySlug(string $storeSlug, string $productSlug): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT p.*, s.name as store_name, s.slug as store_slug, s.theme_color, sc.name as category_name
            FROM products p
            JOIN stores s ON s.id = p.store_id
            LEFT JOIN store_categories sc ON sc.id = p.category_id
            WHERE s.slug = :s_slug AND p.slug = :p_slug AND s.status = "ACTIVE"
            LIMIT 1
        ');
        $stmt->execute([':s_slug' => $storeSlug, ':p_slug' => $productSlug]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            return null;
        }

        return $this->formatProductRow($prod);
    }

    public function listProducts(
        string $storeId,
        ?string $categoryId = null,
        ?string $search = null,
        ?string $status = 'ACTIVE',
        string $sort = 'created_at',
        string $order = 'DESC',
        int $limit = 50,
        int $offset = 0
    ): array {
        $where = ['p.store_id = :sid'];
        $params = [':sid' => $storeId];

        if (!empty($status)) {
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        }

        if (!empty($categoryId)) {
            $where[] = 'p.category_id = :cid';
            $params[':cid'] = $categoryId;
        }

        if (!empty($search)) {
            $where[] = '(p.name LIKE :search OR p.description LIKE :search OR p.sku LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $allowedSorts = ['created_at', 'price_cents', 'name', 'inventory'];
        $sortCol = in_array($sort, $allowedSorts, true) ? $sort : 'created_at';
        $sortDir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM products p {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT p.*, sc.name as category_name
            FROM products p
            LEFT JOIN store_categories sc ON sc.id = p.category_id
            {$whereClause}
            ORDER BY p.{$sortCol} {$sortDir}
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $products = array_map([$this, 'formatProductRow'], $rows);

        return [
            'products' => $products,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + count($products)) < $total,
            ],
        ];
    }

    public function updateProduct(string $userId, string $storeId, string $productId, array $data, bool $isAdmin = false): array
    {
        $this->verifyStoreAccess($userId, $storeId, $isAdmin);
        $prod = $this->getProduct($storeId, $productId);
        if (!$prod) {
            throw new InvalidArgumentException('Product not found.');
        }

        $fields = [];
        $params = [':id' => $productId, ':sid' => $storeId, ':now' => date('Y-m-d H:i:s')];

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (strlen($name) < 2) {
                throw new InvalidArgumentException('Product name must be at least 2 characters.');
            }
            $fields[] = 'name = :name';
            $params[':name'] = $name;
        }

        if (isset($data['slug'])) {
            $slug = self::slugify($data['slug']);
            if ($slug !== $prod['slug']) {
                $chk = $this->pdo->prepare('SELECT id FROM products WHERE store_id = :sid AND slug = :slug AND id != :id');
                $chk->execute([':sid' => $storeId, ':slug' => $slug, ':id' => $productId]);
                if ($chk->fetchColumn()) {
                    throw new InvalidArgumentException("A product with slug '{$slug}' already exists in this store.");
                }
                $fields[] = 'slug = :slug';
                $params[':slug'] = $slug;
            }
        }

        if (isset($data['price_cents'])) {
            $priceCents = (int)$data['price_cents'];
            if ($priceCents < 0) {
                throw new InvalidArgumentException('Product price must be non-negative integer cents.');
            }
            $fields[] = 'price_cents = :price';
            $params[':price'] = $priceCents;
        }

        if (isset($data['inventory'])) {
            $inv = (int)$data['inventory'];
            if ($inv < 0) {
                throw new InvalidArgumentException('Inventory cannot be negative.');
            }
            $fields[] = 'inventory = :inv';
            $params[':inv'] = $inv;
        }

        if (array_key_exists('short_description', $data)) {
            $fields[] = 'short_description = :sdesc';
            $params[':sdesc'] = $data['short_description'];
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = :desc';
            $params[':desc'] = $data['description'];
        }

        if (array_key_exists('sku', $data)) {
            $fields[] = 'sku = :sku';
            $params[':sku'] = $data['sku'];
        }

        if (array_key_exists('category_id', $data)) {
            $fields[] = 'category_id = :cat_id';
            $params[':cat_id'] = $data['category_id'] ?: null;
        }

        if (isset($data['status'])) {
            $valid = ['ACTIVE', 'INACTIVE', 'ARCHIVED'];
            if (!in_array($data['status'], $valid, true)) {
                throw new InvalidArgumentException('Invalid product status.');
            }
            $fields[] = 'status = :status';
            $params[':status'] = $data['status'];
        }

        if (isset($data['images'])) {
            $fields[] = 'images_json = :images';
            $params[':images'] = json_encode($data['images']);
        }

        if (isset($data['variants'])) {
            $fields[] = 'variants_json = :variants';
            $params[':variants'] = json_encode($data['variants']);
        }

        if (empty($fields)) {
            return $this->getProduct($storeId, $productId);
        }

        $fields[] = 'updated_at = :now';
        $sql = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = :id AND store_id = :sid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->getProduct($storeId, $productId);
    }

    public function deleteProduct(string $userId, string $storeId, string $productId, bool $isAdmin = false): bool
    {
        $this->verifyStoreAccess($userId, $storeId, $isAdmin);
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = :id AND store_id = :sid');
        return $stmt->execute([':id' => $productId, ':sid' => $storeId]);
    }

    private function formatProductRow(array $row): array
    {
        $row['price_cents'] = (int)$row['price_cents'];
        $row['currency'] = $row['currency'] ?? 'EUR';
        $row['formatted_price'] = self::formatCents($row['price_cents'], $row['currency']);
        $row['inventory'] = (int)$row['inventory'];
        $row['in_stock'] = $row['inventory'] > 0;
        $row['images'] = !empty($row['images_json']) ? json_decode($row['images_json'], true) : [];
        $row['variants'] = !empty($row['variants_json']) ? json_decode($row['variants_json'], true) : [];
        return $row;
    }
}
