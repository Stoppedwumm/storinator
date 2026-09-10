-- =============================================================================
-- Migration 006: File Sharing (Phase 8)
-- Tokenized share links, password hashes, expiries, download limits & counters
-- =============================================================================

CREATE TABLE IF NOT EXISTS file_shares (
    id VARCHAR(64) PRIMARY KEY,                         -- shr_...
    user_id VARCHAR(64) NOT NULL,                      -- owner of the resource
    file_id VARCHAR(64) DEFAULT NULL,                  -- shared BigStore file ID
    directory_id VARCHAR(64) DEFAULT NULL,             -- shared BigStore directory ID
    resource_type VARCHAR(16) NOT NULL DEFAULT 'FILE', -- 'FILE' or 'DIRECTORY'
    resource_name VARCHAR(255) NOT NULL,               -- display name of shared item
    token VARCHAR(64) NOT NULL UNIQUE,                 -- public random token (e.g. 7bcPdsA2)
    password_hash VARCHAR(255) DEFAULT NULL,           -- Argon2id password hash
    expires_at TIMESTAMP DEFAULT NULL,                 -- expiration timestamp
    download_enabled BOOLEAN NOT NULL DEFAULT 1,       -- 1 = allow download, 0 = preview only
    max_downloads INTEGER DEFAULT NULL,                -- null = unlimited
    download_count INTEGER NOT NULL DEFAULT 0,
    view_count INTEGER NOT NULL DEFAULT 0,
    is_revoked BOOLEAN NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS share_access_tokens (
    id VARCHAR(64) PRIMARY KEY,                         -- sat_...
    share_id VARCHAR(64) NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,            -- sha256 hash of unlock token
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (share_id) REFERENCES file_shares(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_file_shares_token ON file_shares(token);
CREATE INDEX IF NOT EXISTS idx_file_shares_user ON file_shares(user_id);
CREATE INDEX IF NOT EXISTS idx_file_shares_file ON file_shares(file_id);
CREATE INDEX IF NOT EXISTS idx_file_shares_dir ON file_shares(directory_id);
CREATE INDEX IF NOT EXISTS idx_share_tokens_hash ON share_access_tokens(token_hash);
