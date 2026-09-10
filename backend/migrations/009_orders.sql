-- ============================================================================
-- Migration: 009_orders.sql
-- Description: Orders and order_items enhancement for checkout and fees
-- ============================================================================

-- 1. Enhance orders table
ALTER TABLE orders ADD COLUMN order_number VARCHAR(64);
ALTER TABLE orders ADD COLUMN partner_id VARCHAR(64);
ALTER TABLE orders ADD COLUMN subtotal_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN platform_fee_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN currency VARCHAR(16) NOT NULL DEFAULT 'EUR';
ALTER TABLE orders ADD COLUMN shipping_address_json TEXT;
ALTER TABLE orders ADD COLUMN notes TEXT;

-- 2. Enhance order_items table
ALTER TABLE order_items ADD COLUMN sku VARCHAR(64);
ALTER TABLE order_items ADD COLUMN total_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE order_items ADD COLUMN variant_json TEXT;

-- 3. Indexes for fast retrieval and uniqueness
CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_number ON orders(order_number);
CREATE INDEX IF NOT EXISTS idx_orders_user ON orders(user_id);
CREATE INDEX IF NOT EXISTS idx_orders_store ON orders(store_id);
CREATE INDEX IF NOT EXISTS idx_orders_partner ON orders(partner_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id);
CREATE INDEX IF NOT EXISTS idx_order_items_product ON order_items(product_id);
CREATE INDEX IF NOT EXISTS idx_invoices_order ON invoices(order_id);
CREATE INDEX IF NOT EXISTS idx_invoices_partner ON invoices(partner_id);
CREATE INDEX IF NOT EXISTS idx_invoices_user ON invoices(user_id);
