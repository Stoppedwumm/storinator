-- Migration 005: Partner Billing, Debt Settlements, and Statements (Phase 7)

-- Enhance partner_payments with payment method, reference, post-debt snapshot, and idempotency key
ALTER TABLE partner_payments ADD COLUMN payment_method VARCHAR(64) DEFAULT 'MANUAL';
ALTER TABLE partner_payments ADD COLUMN reference_number VARCHAR(128);
ALTER TABLE partner_payments ADD COLUMN debt_after_cents INTEGER DEFAULT 0;
ALTER TABLE partner_payments ADD COLUMN idempotency_key VARCHAR(128);

-- Partner billing statements table (for periodic accounting statements)
CREATE TABLE IF NOT EXISTS partner_statements (
    id VARCHAR(64) PRIMARY KEY, -- stm_...
    partner_id VARCHAR(64) NOT NULL,
    statement_period VARCHAR(32) NOT NULL, -- e.g. "2026-09"
    opening_debt_cents INTEGER NOT NULL DEFAULT 0,
    total_charges_cents INTEGER NOT NULL DEFAULT 0,
    total_payments_cents INTEGER NOT NULL DEFAULT 0,
    closing_debt_cents INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'GENERATED', -- GENERATED, CLOSED
    notes TEXT,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
);

-- Optimize queries for partner ledger history and statements
CREATE INDEX IF NOT EXISTS idx_pbe_partner_created ON partner_billing_entries(partner_id, created_at);
CREATE INDEX IF NOT EXISTS idx_pbe_operation ON partner_billing_entries(operation_type);
CREATE INDEX IF NOT EXISTS idx_ppay_partner_created ON partner_payments(partner_id, created_at);
CREATE INDEX IF NOT EXISTS idx_ppay_idempotency ON partner_payments(idempotency_key);
CREATE INDEX IF NOT EXISTS idx_pstm_partner ON partner_statements(partner_id, statement_period);
