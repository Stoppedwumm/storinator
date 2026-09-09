-- BigStore Initial Schema Migration (001_initial.sql)

CREATE TABLE IF NOT EXISTS bigstore_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS storage_accounts (
    id VARCHAR(64) PRIMARY KEY,
    owner_type VARCHAR(32) NOT NULL, -- user, store, public
    owner_id VARCHAR(64) NOT NULL UNIQUE,
    quota_bytes BIGINT NOT NULL DEFAULT 53687091200, -- 50 GiB default for subscribers
    used_bytes BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS directories (
    id VARCHAR(64) PRIMARY KEY,
    owner_type VARCHAR(32) NOT NULL,
    owner_id VARCHAR(64) NOT NULL,
    parent_id VARCHAR(64) DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    path TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP DEFAULT NULL,
    FOREIGN KEY (parent_id) REFERENCES directories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS files (
    id VARCHAR(64) PRIMARY KEY,
    owner_type VARCHAR(32) NOT NULL,
    owner_id VARCHAR(64) NOT NULL,
    directory_id VARCHAR(64) DEFAULT NULL,
    stored_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(128) NOT NULL,
    size_bytes BIGINT NOT NULL,
    sha256 VARCHAR(64) NOT NULL,
    storage_path TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP DEFAULT NULL,
    FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS file_versions (
    id VARCHAR(64) PRIMARY KEY,
    file_id VARCHAR(64) NOT NULL,
    version_number INTEGER NOT NULL,
    size_bytes BIGINT NOT NULL,
    sha256 VARCHAR(64) NOT NULL,
    storage_path TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS uploads (
    id VARCHAR(64) PRIMARY KEY,
    owner_type VARCHAR(32) NOT NULL,
    owner_id VARCHAR(64) NOT NULL,
    directory_id VARCHAR(64) DEFAULT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(128) NOT NULL,
    total_size_bytes BIGINT NOT NULL,
    total_chunks INTEGER NOT NULL,
    received_chunks INTEGER NOT NULL DEFAULT 0,
    sha256 VARCHAR(64) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'UPLOADING', -- UPLOADING, COMPLETED, ABORTED, FAILED
    temp_dir TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS upload_chunks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    upload_id VARCHAR(64) NOT NULL,
    chunk_index INTEGER NOT NULL,
    chunk_size_bytes BIGINT NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(upload_id, chunk_index),
    FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS shares (
    id VARCHAR(64) PRIMARY KEY,
    file_id VARCHAR(64) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    is_public BOOLEAN DEFAULT 1,
    download_enabled BOOLEAN DEFAULT 1,
    password_hash VARCHAR(255) DEFAULT NULL,
    max_downloads INTEGER DEFAULT NULL,
    download_count INTEGER NOT NULL DEFAULT 0,
    view_count INTEGER NOT NULL DEFAULT 0,
    expires_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP DEFAULT NULL,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS share_access_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    share_id VARCHAR(64) NOT NULL,
    action VARCHAR(32) NOT NULL, -- view, download
    ip_hash VARCHAR(64),
    user_agent TEXT,
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS media (
    id VARCHAR(64) PRIMARY KEY,
    file_id VARCHAR(64) NOT NULL UNIQUE,
    media_type VARCHAR(32) NOT NULL, -- video, audio
    duration_seconds REAL DEFAULT NULL,
    codec VARCHAR(64) DEFAULT NULL,
    resolution VARCHAR(32) DEFAULT NULL,
    bitrate INTEGER DEFAULT NULL,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS media_stream_tokens (
    token VARCHAR(64) PRIMARY KEY,
    file_id VARCHAR(64) NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS thumbnails (
    id VARCHAR(64) PRIMARY KEY,
    file_id VARCHAR(64) NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    storage_path TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS trash (
    id VARCHAR(64) PRIMARY KEY,
    file_id VARCHAR(64) NOT NULL,
    original_directory_id VARCHAR(64) DEFAULT NULL,
    deleted_by VARCHAR(64) NOT NULL,
    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS storage_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type VARCHAR(64) NOT NULL,
    owner_type VARCHAR(32) NOT NULL,
    owner_id VARCHAR(64) NOT NULL,
    file_id VARCHAR(64) DEFAULT NULL,
    bytes_delta BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_files_owner ON files(owner_type, owner_id);
CREATE INDEX IF NOT EXISTS idx_files_directory ON files(directory_id);
CREATE INDEX IF NOT EXISTS idx_shares_token ON shares(token);
