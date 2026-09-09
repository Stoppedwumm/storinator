# File Sharing & Store Platform — Master Documentation

A modular, self-hosted web platform providing personal cloud storage, secure file sharing, streaming media management, partner-operated storefronts, and internal financial ledgers.

---

## 1. System Architecture

The platform consists of three logically and physically isolated services:

```text
                               Public Internet
                                      │
                                      ▼
                      ┌───────────────────────────────┐
                      │        Webapp (Nginx)         │
                      │  Port 80 (Host: ${PORT:-8080})│
                      └───────────────┬───────────────┘
                                      │ (frontend_net)
                                      ▼
                      ┌───────────────────────────────┐
                      │         Backend (PHP)         │
                      │     REST API (Port 8000)      │
                      │     SQLite: backend.sqlite    │
                      └───────────────┬───────────────┘
                                      │ (backend_net)
                                      ▼
                      ┌───────────────────────────────┐
                      │        BigStore (Node)        │
                      │ Internal Storage (Port 8080)  │
                      │     SQLite: bigstore.sqlite   │
                      └───────────────────────────────┘
```

### Security & Isolation Boundaries
1. **Frontend Isolation**: The `webapp` reverse proxy communicates only with `backend` on `frontend_net`.
2. **BigStore Sequestration**: The `bigstore` container resides exclusively on `backend_net` (`internal: true`). Direct access from the browser or public internet is impossible.
3. **Service Secret Authentication**: Every internal request from Backend to BigStore requires the `X-Internal-Service-Token` header.
4. **Append-Only Financial Accounting**: All customer balances are stored in integer minor units (cents) with ledger transaction history.

---

## 2. Directory Structure

```text
/
├── webapp/                 # Frontend SPA & reverse proxy
│   ├── src/
│   │   ├── css/            # Modular stylesheets (variables, landing, dashboard, etc.)
│   │   ├── js/             # Vanilla ES Modules, router, api client, reactive state
│   │   └── index.html      # HTML5 template
│   ├── nginx.conf          # Reverse proxy config (/api/* -> backend, / -> static)
│   ├── webpack.config.js   # Production & dev build configuration
│   └── Dockerfile          # Multi-stage build (Node 24 -> Nginx Alpine)
│
├── backend/                # Primary application & authorization service
│   ├── public/             # Front controller (index.php)
│   ├── src/                # Core, Controllers, Services, BigStore client, Repositories
│   ├── migrations/         # Sequential numbered SQL migrations (001_initial.sql)
│   ├── bin/migrate.php     # Automated migration runner
│   ├── tests/              # Automated backend test suite
│   └── Dockerfile          # PHP 8.4 CLI / Alpine container
│
├── bigstore/               # Private object storage, streaming & quota service
│   ├── src/                # Server, SQLite DB, storage trees, security token check
│   ├── migrations/         # BigStore SQL migrations (001_initial.sql)
│   ├── tests/              # Automated storage and health tests
│   └── Dockerfile          # Node 24 Debian Slim container
│
├── scripts/
│   ├── backup.sh           # Safe SQLite online backup script
│   ├── restore.sh          # Backup restoration utility
│   └── test-health.sh      # Automated health verification suite
│
├── docker-compose.yml      # Multi-container service definitions & networks
├── .env.example            # Environment variables template
├── PROJECT_PLAN.md         # Master roadmap and phase tracking
├── AGENTS.md               # Mandatory development rules & agent roles
└── README.md               # Primary operational guide
```

---

## 3. Requirements

* **Docker Engine** 24.0+
* **Docker Compose** v2.20+
* (Optional for local development outside Docker):
  * Node.js 22+ / 24+ and npm 10+
  * PHP 8.4+ with `pdo_sqlite` and `curl`
  * Composer 2.x

---

## 4. Installation & Quick Start

Clone the repository and launch the full stack with Docker Compose:

```bash
# 1. Copy environment configuration
cp .env.example .env

# 2. Build and launch all containers
docker compose up -d --build
```

Access the platform in your browser at:
**`http://localhost:8080`**

---

## 5. Secret Entry Code (Hidden Platform Access)

The homepage presents a corporate startup landing page. To reveal the platform login access:
1. Locate the search field labeled `"Search platform documentation or index..."`.
2. Enter the configured entry code (default: `anticipation2026`).
3. Press **Search** / Enter.
4. The server validates the code, sets a secure session entry cookie, and redirects to `#/login`.

---

## 6. Configuration

All platform settings are managed via the root `.env` file:

| Variable | Description | Default |
|:---|:---|:---|
| `APP_ENV` | Application environment (`development` \| `production`) | `development` |
| `PORT` | External HTTP port published on host | `8080` |
| `ENTRY_CODE` | Secret code to unlock platform login access | `anticipation2026` |
| `SESSION_SECRET` | Backend cookie and session signing secret | `dev_session_secret...` |
| `INTERNAL_BIGSTORE_SECRET` | Shared token between Backend and BigStore | `dev_bigstore_secret...` |
| `DEFAULT_STORAGE_QUOTA_BYTES` | Default storage quota for subscribed users (50 GiB) | `53687091200` |
| `MAX_UPLOAD_SIZE` | Maximum single-file / chunk size limit (5 GiB) | `5368709120` |
| `MOVIE_METADATA_PROVIDER` | Movie metadata engine (`tmdb` \| `omdb`) | `tmdb` |
| `TMDB_API_KEY` | TMDB API authentication token | *(Optional)* |

---

## 7. Automated Health & Verification Tests

### Run Full System Health Check
Run the comprehensive verification script against the running Docker stack:

```bash
./scripts/test-health.sh
```

This verifies:
- Webapp responds on HTTP 200
- Nginx reverse proxy routes `/api/health` to PHP backend
- Backend SQLite database is connected and migrated
- Backend successfully communicates with BigStore across `backend_net`
- BigStore service token authentication strictly rejects unauthorized requests
- Direct external host access to BigStore port 8080 is blocked

### Run Component Unit Tests
```bash
# Backend unit & migration tests
docker compose exec backend php tests/run_tests.php

# BigStore storage & security tests
docker compose exec bigstore npm test
```

---

## 8. Database Migrations

Both services use numbered SQL migrations.
- Backend migrations reside in `backend/migrations/`
- BigStore migrations reside in `bigstore/migrations/`

Migrations run automatically during container startup via their respective entrypoints.
To run migrations manually:

```bash
docker compose exec backend php bin/migrate.php
docker compose exec bigstore npm run migrate
```

---

## 9. Backups & Disaster Recovery

The platform includes atomic SQLite backup utilities that safely snapshot active databases using the SQLite online backup API:

```bash
# Create an atomic timestamped backup
./scripts/backup.sh

# Restore from a backup folder
./scripts/restore.sh ./backups/<backup-directory>
```

---

## 10. Development & Updating

To rebuild containers after making changes:

```bash
docker compose up -d --build
```

To view real-time logs across all services:

```bash
docker compose logs -f
```
