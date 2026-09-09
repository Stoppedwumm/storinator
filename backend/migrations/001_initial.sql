-- Backend Core Initial Schema Migration (001_initial.sql)

CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS roles (
    id VARCHAR(32) PRIMARY KEY, -- 'CUSTOMER', 'PARTNER', 'ADMIN'
    description TEXT NOT NULL
);

INSERT OR IGNORE INTO roles (id, description) VALUES
('CUSTOMER', 'Standard customer account with personal storage and shopping access'),
('PARTNER', 'Merchant/Partner business operator managing stores and customer billing'),
('ADMIN', 'Full system platform administrator');

CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(64) PRIMARY KEY, -- usr_...
    username VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE', -- ACTIVE, SUSPENDED, DISABLED
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id VARCHAR(64) NOT NULL,
    role_id VARCHAR(32) NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(64) PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS wallets (
    id VARCHAR(64) PRIMARY KEY, -- wal_...
    user_id VARCHAR(64) NOT NULL UNIQUE,
    balance_cents INTEGER NOT NULL DEFAULT 0, -- Integer minor units (Spec Section 7)
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id VARCHAR(64) PRIMARY KEY, -- wtx_...
    wallet_id VARCHAR(64) NOT NULL,
    amount_cents INTEGER NOT NULL, -- positive for credits, negative for deductions
    balance_after_cents INTEGER NOT NULL,
    transaction_type VARCHAR(32) NOT NULL, -- PARTNER_TOPUP, STORE_PURCHASE, REFUND, etc.
    reference_type VARCHAR(32),
    reference_id VARCHAR(64),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS partners (
    id VARCHAR(64) PRIMARY KEY, -- par_...
    user_id VARCHAR(64) NOT NULL UNIQUE,
    company_name VARCHAR(255) NOT NULL,
    debt_cents INTEGER NOT NULL DEFAULT 0, -- integer minor units (Spec Section 8)
    invoice_retention_enabled BOOLEAN DEFAULT 1, -- Spec Section 10
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS partner_billing_entries (
    id VARCHAR(64) PRIMARY KEY, -- pbe_...
    partner_id VARCHAR(64) NOT NULL,
    amount_cents INTEGER NOT NULL,
    operation_type VARCHAR(32) NOT NULL, -- CUSTOMER_TOPUP, SUBSCRIPTION_RENEWAL
    reference_id VARCHAR(64),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS partner_payments (
    id VARCHAR(64) PRIMARY KEY, -- pay_...
    partner_id VARCHAR(64) NOT NULL,
    amount_cents INTEGER NOT NULL,
    admin_id VARCHAR(64) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id VARCHAR(64) PRIMARY KEY, -- sub_...
    user_id VARCHAR(64) NOT NULL UNIQUE,
    partner_id VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE', -- ACTIVE, EXPIRED, SUSPENDED
    quota_bytes BIGINT NOT NULL DEFAULT 53687091200, -- 50 GiB exactly
    starts_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS subscription_requests (
    id VARCHAR(64) PRIMARY KEY, -- srq_...
    user_id VARCHAR(64) NOT NULL,
    partner_id VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'REQUESTED', -- REQUESTED, APPROVED, REJECTED
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS stores (
    id VARCHAR(64) PRIMARY KEY, -- sto_...
    partner_id VARCHAR(64) NOT NULL,
    slug VARCHAR(128) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS products (
    id VARCHAR(64) PRIMARY KEY, -- prd_...
    store_id VARCHAR(64) NOT NULL,
    slug VARCHAR(128) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price_cents INTEGER NOT NULL,
    inventory INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (store_id, slug),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS orders (
    id VARCHAR(64) PRIMARY KEY, -- ord_...
    user_id VARCHAR(64) NOT NULL,
    store_id VARCHAR(64) NOT NULL,
    total_cents INTEGER NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PAID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (store_id) REFERENCES stores(id)
);

CREATE TABLE IF NOT EXISTS order_items (
    id VARCHAR(64) PRIMARY KEY,
    order_id VARCHAR(64) NOT NULL,
    product_id VARCHAR(64) NOT NULL,
    product_name_snapshot VARCHAR(255) NOT NULL,
    unit_price_cents INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS invoices (
    id VARCHAR(64) PRIMARY KEY, -- inv_...
    order_id VARCHAR(64) NOT NULL UNIQUE,
    partner_id VARCHAR(64) NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    invoice_number VARCHAR(64) NOT NULL UNIQUE,
    content_json TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS public_directory_items (
    id VARCHAR(64) PRIMARY KEY, -- pdi_...
    title VARCHAR(255) NOT NULL,
    description TEXT,
    item_type VARCHAR(32) NOT NULL, -- FILE, MOVIE, COLLECTION
    bigstore_file_id VARCHAR(64),
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_featured BOOLEAN DEFAULT 0,
    is_visible BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS movies (
    id VARCHAR(64) PRIMARY KEY, -- mov_...
    title VARCHAR(255) NOT NULL,
    original_title VARCHAR(255),
    release_year INTEGER,
    description TEXT,
    poster_path TEXT,
    backdrop_path TEXT,
    bigstore_file_id VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS entry_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code_hash VARCHAR(255) NOT NULL,
    description VARCHAR(255),
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id VARCHAR(64),
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(64),
    target_id VARCHAR(64),
    ip_address VARCHAR(45),
    metadata TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    key VARCHAR(128) PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token_hash);
CREATE INDEX IF NOT EXISTS idx_wtx_wallet ON wallet_transactions(wallet_id);
CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit_logs(actor_id);
