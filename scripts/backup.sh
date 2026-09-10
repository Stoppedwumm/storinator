#!/usr/bin/env bash
set -euo pipefail

# Platform Safe Backup Utility (Spec Section 61)
# Uses SQLite online backup API to prevent corruption during active writes

BACKUP_DIR="${1:-./backups/$(date +%Y%m%d_%H%M%S)}"
mkdir -p "${BACKUP_DIR}"

echo "===================================================="
echo "Starting Platform Backup -> ${BACKUP_DIR}"
echo "===================================================="

# 1. Back up backend SQLite safely via docker container or host
BACKEND_CONTAINER=$(docker ps --format '{{.Names}}' | grep -E 'platform-(webapp|backend)' | head -n 1 || true)
if [ -n "$BACKEND_CONTAINER" ]; then
    echo "[1/3] Backing up Backend Database (${BACKEND_CONTAINER})..."
    docker exec "$BACKEND_CONTAINER" sqlite3 /data/backend.sqlite ".backup '/data/backend_backup.sqlite'"
    docker cp "${BACKEND_CONTAINER}:/data/backend_backup.sqlite" "${BACKUP_DIR}/backend.sqlite"
    docker exec "$BACKEND_CONTAINER" rm -f /data/backend_backup.sqlite
else
    echo "[1/3] Backend container not running, checking local files..."
    if [ -f "./backend/data/backend.sqlite" ]; then
        sqlite3 ./backend/data/backend.sqlite ".backup '${BACKUP_DIR}/backend.sqlite'"
    fi
fi

# 2. Back up BigStore SQLite safely via docker container or host
if docker ps --format '{{.Names}}' | grep -q 'platform-bigstore'; then
    echo "[2/3] Backing up BigStore Database (Docker)..."
    docker exec platform-bigstore node -e "
        const Database = require('better-sqlite3');
        const db = new Database(process.env.SQLITE_BIGSTORE_PATH || '/data/databases/bigstore.sqlite');
        db.backup('/data/databases/bigstore_backup.sqlite')
            .then(() => process.exit(0))
            .catch(() => process.exit(1));
    "
    docker cp platform-bigstore:/data/databases/bigstore_backup.sqlite "${BACKUP_DIR}/bigstore.sqlite"
    docker exec platform-bigstore rm -f /data/databases/bigstore_backup.sqlite
else
    echo "[2/3] Bigstore container not running, checking local files..."
    if [ -f "./bigstore/data/databases/bigstore.sqlite" ]; then
        sqlite3 ./bigstore/data/databases/bigstore.sqlite ".backup '${BACKUP_DIR}/bigstore.sqlite'"
    fi
fi

# 3. Save backup metadata
cat <<EOF > "${BACKUP_DIR}/metadata.json"
{
  "timestamp": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
  "version": "1.0.0",
  "app_env": "${APP_ENV:-development}"
}
EOF

echo "[3/3] Created backup manifest: ${BACKUP_DIR}/metadata.json"
echo "===================================================="
echo "Backup Completed Successfully: ${BACKUP_DIR}"
echo "===================================================="
