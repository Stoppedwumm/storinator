-- Migration 003: Subscriptions Enhancements and Event Ledger

ALTER TABLE subscription_requests ADD COLUMN rejection_reason TEXT;
ALTER TABLE subscription_requests ADD COLUMN resolved_by VARCHAR(64);

CREATE TABLE IF NOT EXISTS subscription_events (
    id VARCHAR(64) PRIMARY KEY, -- sev_...
    subscription_id VARCHAR(64),
    user_id VARCHAR(64) NOT NULL,
    partner_id VARCHAR(64) NOT NULL,
    event_type VARCHAR(32) NOT NULL, -- REQUESTED, APPROVED, REJECTED, RENEWED, CANCELLED, EXPIRED
    actor_id VARCHAR(64) NOT NULL,
    fee_cents INTEGER DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_sub_user ON subscriptions(user_id);
CREATE INDEX IF NOT EXISTS idx_sub_partner ON subscriptions(partner_id);
CREATE INDEX IF NOT EXISTS idx_sub_status ON subscriptions(status);
CREATE INDEX IF NOT EXISTS idx_subreq_user ON subscription_requests(user_id);
CREATE INDEX IF NOT EXISTS idx_subreq_partner ON subscription_requests(partner_id);
CREATE INDEX IF NOT EXISTS idx_subreq_status ON subscription_requests(status);
CREATE INDEX IF NOT EXISTS idx_subevents_sub ON subscription_events(subscription_id);
CREATE INDEX IF NOT EXISTS idx_subevents_user ON subscription_events(user_id);
