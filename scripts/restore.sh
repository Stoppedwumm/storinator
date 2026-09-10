#!/usr/bin/env bash
set -euo pipefail

# Platform Safe Restore Utility (Spec Section 61)

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <backup-directory>"
    exit 1
fi

RESTORE_DIR="$1"

if [ ! -d "${RESTORE_DIR}" ]; then
    echo "Error: Directory ${RESTORE_DIR} does not exist."
    exit 1
fi

echo "===================================================="
echo "Restoring Platform from ${RESTORE_DIR}"
echo "===================================================="

# 1. Restore Backend SQLite
if [ -f "${RESTORE_DIR}/backend.sqlite" ]; then
    echo "[1/2] Restoring Backend Database..."
    BACKEND_CONTAINER=$(docker ps --format '{{.Names}}' | grep -E 'platform-(webapp|backend)' | head -n 1 || true)
    if [ -n "$BACKEND_CONTAINER" ]; then
        docker cp "${RESTORE_DIR}/backend.sqlite" "${BACKEND_CONTAINER}:/data/backend.sqlite"
    else
        cp "${RESTORE_DIR}/backend.sqlite" ./backend/data/backend.sqlite
    fi
fi

# 2. Restore BigStore SQLite
if [ -f "${RESTORE_DIR}/bigstore.sqlite" ]; then
    echo "[2/2] Restoring BigStore Database..."
    if docker ps --format '{{.Names}}' | grep -q 'platform-bigstore'; then
        docker cp "${RESTORE_DIR}/bigstore.sqlite" platform-bigstore:/data/databases/bigstore.sqlite
    else
        cp "${RESTORE_DIR}/bigstore.sqlite" ./bigstore/data/databases/bigstore.sqlite
    fi
fi

echo "===================================================="
echo "Restore Completed Successfully."
echo "===================================================="
