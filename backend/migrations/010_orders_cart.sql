-- ============================================================================
-- Migration: 010_orders_cart.sql
-- Description: Cart items table, order events ledger, and order idempotency
-- ============================================================================

-- 1. Add idempotency_key to orders table
ALTER TABLE orders ADD COLUMN idempotency_key VARCHAR(128);
CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_idempotency ON orders(idempotency_key);

-- 2. Cart items table for persistent customer shopping carts
CREATE TABLE IF NOT EXISTS cart_items (
    id VARCHAR(64) PRIMARY KEY, -- crt_...
    user_id VARCHAR(64) NOT NULL,
    store_id VARCHAR(64) NOT NULL,
    product_id VARCHAR(64) NOT NULL,
    variant_json TEXT,
    quantity INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_cart_items_user ON cart_items(user_id);
CREATE INDEX IF NOT EXISTS idx_cart_items_store ON cart_items(store_id);
CREATE INDEX IF NOT EXISTS idx_cart_items_product ON cart_items(product_id);

-- 3. Order events table for audit trail of order lifecycle transitions
CREATE TABLE IF NOT EXISTS order_events (
    id VARCHAR(64) PRIMARY KEY, -- oev_...
    order_id VARCHAR(64) NOT NULL,
    actor_id VARCHAR(64),
    event_type VARCHAR(64) NOT NULL, -- ORDER_PLACED, STATUS_CHANGED, FULFILLED, CANCELLED, REFUNDED
    details_json TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_order_events_order ON order_events(order_id);
