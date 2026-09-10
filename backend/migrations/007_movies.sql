-- =============================================================================
-- Migration 007: Movie Mode, Metadata & Short-Lived Streaming Tokens
-- =============================================================================

DROP TABLE IF EXISTS movies;

CREATE TABLE movies (
    id VARCHAR(64) PRIMARY KEY, -- mov_...
    user_id VARCHAR(64) NOT NULL,
    file_id VARCHAR(64) NOT NULL,
    directory_id VARCHAR(64) DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    original_title VARCHAR(255) DEFAULT NULL,
    release_year INTEGER DEFAULT NULL,
    description TEXT DEFAULT NULL,
    poster_url TEXT DEFAULT NULL,
    backdrop_url TEXT DEFAULT NULL,
    genres TEXT DEFAULT NULL, -- comma-separated or JSON
    runtime_minutes INTEGER DEFAULT NULL,
    rating VARCHAR(32) DEFAULT NULL,
    director VARCHAR(255) DEFAULT NULL,
    cast_members TEXT DEFAULT NULL,
    confidence_score REAL DEFAULT 1.0,
    is_public BOOLEAN DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_movies_user ON movies(user_id);
CREATE INDEX IF NOT EXISTS idx_movies_file ON movies(file_id);
CREATE INDEX IF NOT EXISTS idx_movies_year ON movies(release_year);
CREATE INDEX IF NOT EXISTS idx_movies_title ON movies(title);
CREATE INDEX IF NOT EXISTS idx_movies_public ON movies(is_public);

-- Short-lived tokens for authorized HTTP Range streaming (Rule 19)
CREATE TABLE IF NOT EXISTS stream_tokens (
    token VARCHAR(128) PRIMARY KEY, -- stk_...
    file_id VARCHAR(64) NOT NULL,
    movie_id VARCHAR(64) DEFAULT NULL,
    user_id VARCHAR(64) DEFAULT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_stream_tokens_file ON stream_tokens(file_id);
CREATE INDEX IF NOT EXISTS idx_stream_tokens_expires ON stream_tokens(expires_at);

-- Folders explicitly designated as Movie Folders
CREATE TABLE IF NOT EXISTS movie_folders (
    id VARCHAR(64) PRIMARY KEY, -- mfd_...
    user_id VARCHAR(64) NOT NULL,
    directory_id VARCHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user_id, directory_id)
);

CREATE INDEX IF NOT EXISTS idx_movie_folders_user ON movie_folders(user_id);
