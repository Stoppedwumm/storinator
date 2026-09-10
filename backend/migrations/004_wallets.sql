-- Migration 004: Wallet Idempotency and Performance Indexes
-- Storinator Platform Phase 6: Wallet & Ledger System

-- Add idempotency_key to wallet_transactions for strict duplicate prevention
ALTER TABLE wallet_transactions ADD COLUMN idempotency_key VARCHAR(128) DEFAULT NULL;

-- Index for fast lookup by idempotency key
CREATE INDEX IF NOT EXISTS idx_wallet_tx_idempotency ON wallet_transactions(idempotency_key);

-- Index for fast chronological pagination of wallet ledger
CREATE INDEX IF NOT EXISTS idx_wallet_tx_wallet_created ON wallet_transactions(wallet_id, created_at DESC);

-- Index for wallets user lookup
CREATE INDEX IF NOT EXISTS idx_wallets_user ON wallets(user_id);
