-- ============================================================================
-- Migration: 008_stores.sql
-- Description: Storefront branding, categories, product variants, and inventory
-- ============================================================================

-- 1. Enhance stores table
ALTER TABLE stores ADD COLUMN logo_url TEXT;
ALTER TABLE stores ADD COLUMN banner_url TEXT;
ALTER TABLE stores ADD COLUMN theme_color VARCHAR(32) DEFAULT '#6366f1';
ALTER TABLE stores ADD COLUMN contact_email VARCHAR(255);
ALTER TABLE stores ADD COLUMN contact_phone VARCHAR(64);
ALTER TABLE stores ADD COLUMN terms_content TEXT;
ALTER TABLE stores ADD COLUMN settings_json TEXT;

-- 2. Store categories table
CREATE TABLE IF NOT EXISTS store_categories (
    id VARCHAR(64) PRIMARY KEY, -- sct_...
    store_id VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    slug VARCHAR(128) NOT NULL,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (store_id, slug),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
);

-- 3. Enhance products table
ALTER TABLE products ADD COLUMN short_description TEXT;
ALTER TABLE products ADD COLUMN currency VARCHAR(16) DEFAULT 'EUR';
ALTER TABLE products ADD COLUMN sku VARCHAR(64);
ALTER TABLE products ADD COLUMN category_id VARCHAR(64);
ALTER TABLE products ADD COLUMN images_json TEXT;
ALTER TABLE products ADD COLUMN variants_json TEXT;

-- 4. Indexes for performance and uniqueness
CREATE INDEX IF NOT EXISTS idx_stores_slug ON stores(slug);
CREATE INDEX IF NOT EXISTS idx_stores_partner ON stores(partner_id);
CREATE INDEX IF NOT EXISTS idx_store_categories_store ON store_categories(store_id);
CREATE INDEX IF NOT EXISTS idx_products_store ON products(store_id);
CREATE INDEX IF NOT EXISTS idx_products_category ON products(category_id);
CREATE INDEX IF NOT EXISTS idx_products_status ON products(status);
