# File Sharing / Store Platform — Master Project Plan

## 1. System Architecture Overview

```text
                               Public Internet
                                      |
                                      v
             +--------------------------------------------------+
             |                 Webapp (Nginx)                   |
             |  - Static Assets (Webpack Bundle, HTML5/CSS/JS)   |
             |  - Reverse Proxy for /api/*                      |
             |  - Port 80 (external: ${PORT:-8080})             |
             +--------------------------------------------------+
                                      |
                           (Docker frontend_net)
                                      |
                                      v
             +--------------------------------------------------+
             |                  Backend (PHP)                   |
             |  - PHP 8.4+ Built-in Server / FPM (:8000)        |
             |  - REST-style JSON API                           |
             |  - Centralized Auth & Business Rules             |
             |  - SQLite Database (/data/backend.sqlite)        |
             +--------------------------------------------------+
                                      |
                           (Docker backend_net)
                         (X-Internal-Service-Token)
                                      |
                                      v
             +--------------------------------------------------+
             |                 BigStore (Node)                  |
             |  - Node.js 24 + Express (:8080)                  |
             |  - Storage, Range Streaming, Quota Accounting    |
             |  - Chunked Upload Assembly                       |
             |  - SQLite Database (/data/databases/bigstore.db) |
             +--------------------------------------------------+
```

### Network Isolation Policy
- **External -> Webapp**: Host port mapped to Nginx container.
- **Webapp -> Backend**: Routed internally via `frontend_net`. BigStore is NOT on `frontend_net`.
- **Backend -> BigStore**: Routed internally via `backend_net` using authenticated secret tokens.
- **Webapp -> BigStore**: Direct communication is physically impossible via network isolation.

---

## 2. Phase-by-Phase Roadmap

| Phase | Milestone | Scope / Key Deliverables | Status |
|:------|:----------|:-------------------------|:-------|
| **Phase 1** | **Foundation** | Repo structure, Dockerfiles, Docker Compose, Webpack setup, minimal PHP backend, minimal BigStore service, separate SQLite DBs, internal network isolation, health endpoints, reverse-proxy routing, migration runners, health tests, README. | **COMPLETED** |
| **Phase 2** | **Authentication** | Users, Roles (CUSTOMER, PARTNER, ADMIN), Argon2id passwords, Sessions (SHA-256 tokens), centralized AuthMiddleware & RoleMiddleware, brute-force rate limiter, test accounts seed, test suite. | **COMPLETED** |
| **Phase 3** | **Landing Page** | Corporate minimal teaser website, secret access code input in search bar, server-side code validation, rate limiter, session unlock to login. | **COMPLETED** |
| **Phase 4** | **BigStore Core** | Physical hashed storage paths, directory trees, file metadata, chunked streaming uploads, checksums, quota validation, HTTP Range streaming, file manager UI. | **COMPLETED** |
| **Phase 5** | Subscriptions | 50 GiB storage quota assignment, request/approval/reject workflow, expiration dates; **Subscription pricing: 3.00€/month (300 cents)** billed against partner; platform fee exemption. | Up Next |
| **Phase 6** | Wallet & Ledger | Integer cents balance, append-only transaction ledger, atomic top-up, partner top-up balance allocation, concurrency safeguards. | Pending |
| **Phase 7** | Partner Billing | Partner debt accumulation from top-ups and renewals, admin partial/full debt payment settlement, immutable billing ledgers. | Pending |
| **Phase 8** | File Sharing | Random token share URLs (/s/{token}), download permissions, password protection, view counters, expiration dates. | Pending |
| **Phase 9** | Movie Mode & Streaming | Media file scanning, filename parsing, TMDB/OMDb scraping, cover/backdrop display, HTTP Range streaming with short-lived tokens. | Pending |
| **Phase 10** | Storefronts | Multi-store partner management, slugs, branding, categories, product variants, inventory, BigStore asset storage. | Pending |
| **Phase 11** | Cart & Orders | Persistent cart, **Fee logic: 1.00€ (100 cents) platform fee for non-subscribers (0€ for active 3€/month subscribers)**, atomic balance deduction, inventory reservation, order snapshots, invoices. | Pending |
| **Phase 12** | Store Accounts | Store-specific employee roles (STORE_OWNER, STORE_MANAGER, STORE_STAFF, STORE_SUPPORT) and scoped permissions. | Pending |
| **Phase 13** | Public Directory | Curated directory of files, movies, and collections with admin visibility toggles and custom covers. | Pending |
| **Phase 14** | Admin System | Complete administrative control over users, stores, billing, storage anomalies, audit logs, and system settings. | Pending |
| **Phase 15** | Hardening & Audit | Security audit (IDOR, race conditions, CSRF/XSS, path traversal), backup/restore drills, end-to-end acceptance verification. | Pending |

---

## 3. Phase 1 Detailed Deliverables & Checklist

- [x] Master Project Plan (`PROJECT_PLAN.md`)
- [x] Mandatory Operating Rules (`AGENTS.md`)
- [x] Environment configuration template (`.env.example` & `.env`)
- [x] Docker Compose multi-service architecture with dual networks (`docker-compose.yml`)
- [x] Frontend project (`webapp/`):
  - [x] Webpack configuration bundling JS and CSS (`webapp/webpack.config.js`, `webapp/package.json`)
  - [x] Source HTML and modular CSS design tokens (`webapp/src/`)
  - [x] Nginx configuration serving frontend and proxying `/api/*` (`webapp/nginx.conf`)
  - [x] Webapp Dockerfile with multi-stage build (`webapp/Dockerfile`)
- [x] Backend project (`backend/`):
  - [x] Composer configuration with PSR-4 autoloading (`backend/composer.json`)
  - [x] Front controller and core routing (`backend/public/index.php`, `backend/src/Core/`)
  - [x] Health endpoint checking database and BigStore internal connectivity (`backend/src/Controllers/HealthController.php`)
  - [x] SQLite database connection with WAL mode (`backend/src/Core/Database.php`)
  - [x] Migration runner and initial migration `001_initial.sql`
  - [x] BigStore HTTP client (`backend/src/BigStore/BigStoreClient.php`)
  - [x] Backend Dockerfile (`backend/Dockerfile`)
- [x] BigStore project (`bigstore/`):
  - [x] Express server on internal port 8080 (`bigstore/src/server.js`)
  - [x] SQLite connection with WAL mode (`bigstore/src/database.js`)
  - [x] Internal token authentication middleware (`bigstore/src/security.js`)
  - [x] Internal health endpoint (`GET /internal/health`)
  - [x] Storage directory hierarchy initializers
  - [x] Migration runner and initial migration `001_initial.sql`
  - [x] BigStore Dockerfile (`bigstore/Dockerfile`)
- [x] Scripts:
  - [x] Backup script (`scripts/backup.sh`)
  - [x] Restore script (`scripts/restore.sh`)
  - [x] Automated health test script (`scripts/test-health.sh`)
- [x] Master documentation (`README.md`)
- [x] Acceptance verification:
  - [x] Containers build and start via `docker compose up --build`
  - [x] Health endpoints return 200 OK across all services
  - [x] Frontend loads in browser through Nginx reverse proxy
  - [x] Backend communicates with BigStore over internal network
  - [x] Automated test suite passes (8/8 in `test-health.sh`, 4/4 in backend, 3/3 in BigStore)

---

## 4. Phase 2 Detailed Deliverables & Checklist (Authentication & RBAC)

- [x] Database migration `backend/migrations/002_auth_ratelimit.sql` creating `login_attempts` table.
- [x] Core Request & Response updates (`backend/src/Core/Request.php`, `backend/src/Core/Response.php`):
  - [x] Bearer token & `platform_session` cookie extraction.
  - [x] Client IP extraction and authenticated user context binding.
  - [x] Secure `HttpOnly`, `SameSite=Lax` cookie issuing and deletion.
- [x] Security Services:
  - [x] `backend/src/Services/RateLimiter.php`: Sliding window brute-force protection (5 failed attempts max per 5 min).
  - [x] `backend/src/Services/AuthService.php`: Argon2id password hashing, registration, sessions (SHA-256 tokens), validation, logout.
- [x] Authorization Middlewares:
  - [x] `backend/src/Middleware/AuthMiddleware.php`: Token validation, 401 on missing/expired, 403 on suspended.
  - [x] `backend/src/Middleware/RoleMiddleware.php`: Centralized role enforcement (`ADMIN`, `PARTNER`, `CUSTOMER`), returning 403 `FORBIDDEN`.
- [x] API Controllers & Endpoints:
  - [x] `POST /api/v1/auth/login`: Argon2id verification, session generation, HTTP-only cookie.
  - [x] `POST /api/v1/auth/register`: Customer registration, duplicate email/username rejection (409), wallet creation.
  - [x] `POST /api/v1/auth/logout`: Session revocation and cookie clearing.
  - [x] `GET /api/v1/auth/me` & `/api/v1/users/me`: Profile, role list, wallet balance.
  - [x] Role test endpoints: `/api/v1/customer/ping`, `/api/v1/partner/ping`, `/api/v1/admin/ping`.
- [x] Seeding & Admin CLI Scripts:
  - [x] `backend/bin/create_admin.php`: CLI admin creation script.
  - [x] `backend/bin/seed_users.php`: Automatic seeding of default `admin`, `partner`, and `customer` accounts.
  - [x] `backend/docker-entrypoint.sh` executes migrations and user seeding on startup.
- [x] Frontend Webapp (`webapp/`):
  - [x] Client auth module (`webapp/src/js/auth.js`) with store synchronization.
  - [x] API client (`webapp/src/js/api.js`) with Bearer token header injection and auth helpers.
  - [x] Interactive Login / Registration UI (`webapp/src/js/pages/login.js`) with role testing buttons.
  - [x] Webpack bundle rebuilt with zero errors.
- [x] Automated Test Suite:
  - [x] `scripts/test-auth.sh`: 10/10 automated tests passing (Admin login, Partner login, Customer login, 401 unauthenticated, role boundaries 403/200, logout revocation, self-registration, 409 conflict, 429 rate limit).
  - [x] `scripts/test-health.sh`: 8/8 automated checks passing.

---

## 5. Phase 3 Detailed Deliverables & Checklist (Landing Page & Secret Teaser Access)

- [x] Backend Controller & Routing (`backend/`):
  - [x] `backend/src/Controllers/EntryController.php`:
    - [x] Constant-time comparison for `ENTRY_CODE` (`anticipation2026`).
    - [x] Secondary database lookup against `entry_codes` table (supports Argon2id hashed access codes).
    - [x] Obscured 404 response (`NO_RESULTS`) for non-matching queries, preventing disclosure of hidden access mechanism.
    - [x] Brute-force rate limiting: sliding window 5 attempts per 5 minutes per IP; 6th attempt returns 429 `TOO_MANY_ATTEMPTS`.
    - [x] Issues secure short-lived `platform_entry` HttpOnly cookie upon verification.
    - [x] Records structured audit event in `audit_logs` table (`ENTRY_CODE_VERIFIED`).
    - [x] Provides status verification endpoint (`GET /api/v1/entry/status`).
  - [x] `backend/src/Services/RateLimiter.php`: Dedicated `isEntryRateLimited`, `recordEntryAttempt`, and `clearEntryAttempts` methods.
  - [x] Route registration and aliases in `backend/public/index.php`:
    - `POST /api/v1/entry/verify`
    - `POST /api/entry/verify`
    - `POST /api/entry-code`
    - `GET /api/v1/entry/status`
    - `GET /api/entry/status`
- [x] Frontend Webapp UI & UX (`webapp/`):
  - [x] Corporate minimalist teaser landing page (`webapp/src/js/pages/landing.js`):
    - [x] Hero section with high-performance status badge and headline.
    - [x] Interactive platform index search field supporting secret entry code input.
    - [x] Quick search query chips (`Object Storage`, `Network Isolation`, `Ledger Accounting`, `Merchant Spaces`).
    - [x] Infrastructure section: Dual-network boundary isolation, WAL concurrency, low-latency inter-process mesh.
    - [x] Platform section: Chunked object store, HTTP Range streaming, sovereign store spaces, media enrichment.
    - [x] Security section: Argon2id key derivation, sliding-window rate defense, append-only financial journals.
    - [x] Partners section: Independent store operations, wholesale credit top-ups, configurable invoice retention.
    - [x] Technology section: PHP 8.4 REST API, Node.js streaming core, isolated Docker bridge networking.
    - [x] Coming Soon / Early Access section: Platform preview waitlist inquiry form with instant validation.
  - [x] CSS design system & typography (`webapp/src/css/landing.css`):
    - [x] Dark enterprise styling, subtle radial gradients, glowing input focus states.
    - [x] Unlocking animation glow upon secret entry recognition.
    - [x] Responsive flex and grid layout for desktop, tablet, and mobile devices.
  - [x] Navigation & Router (`webapp/src/js/router.js`, `webapp/src/js/components/header.js`):
    - [x] In-page smooth section anchor scrolling (`#infrastructure`, `#platform`, `#security`, etc.).
    - [x] Reactive header state updating when session or entry is unlocked.
- [x] Automated Test Suite:
  - [x] `scripts/test-landing.sh`: 17/17 automated tests passing:
    - HTML markup & title delivery.
    - CSS and JS asset bundle verification (HTTP 200).
    - Missing code parameter validation (HTTP 400).
    - Failed search query obscurity (HTTP 404 `NO_RESULTS`).
    - Secret entry code verification (`anticipation2026`) issuing HttpOnly `platform_entry` cookie.
    - Route aliases (`/api/entry-code` and `/api/entry/verify`).
    - Status check without cookie (unlocked: false) and with cookie (unlocked: true).
    - Brute-force rate limiting blocking excess attempts (HTTP 429 `TOO_MANY_ATTEMPTS`).
    - SQLite database audit log presence verification.
- [x] Full System Health & Regression Check:
  - [x] `scripts/test-health.sh`: 8/8 tests passed.
  - [x] `scripts/test-auth.sh`: 10/10 tests passed.
  - [x] `scripts/test-landing.sh`: 17/17 tests passed.
  - [x] Total: 35 passing tests, 0 failures.

---

## 6. Fee Structure & Subscription Pricing Rules (Spec Addition)

In accordance with financial integrity rules (Rule 6: integer minor units only, Rule 7: ledger mutation required):

1. **Subscribers**:
   - **Monthly Subscription Fee**: **3.00€ / month** (`300` cents).
   - **Benefits**:
     - 50 GiB storage quota on BigStore.
     - **0.00€ platform fee** on store orders and checkout transactions.
   - **Billing**: Recorded as a `SUBSCRIPTION_RENEWAL` billing entry (`amount_cents = 300`) accumulating against the associated partner debt ledger.

2. **Non-Subscribers**:
   - **Platform Fee**: **1.00€** (`100` cents) platform fee charged per checkout order/transaction.
   - **Enforcement**: Server-side checkout calculation in Phase 11 (`amount_cents + 100` cents platform fee). Cannot be bypassed by frontend tampering (Rule 11).
   - **Ledger Audit**: Platform fee recorded as a separate ledger line item or breakdown on order creation.

---

## 6. Phase 4 Detailed Deliverables & Checklist (BigStore Core Storage Engine & Streaming Uploads)

- [x] BigStore Internal Storage Core (`bigstore/`):
  - [x] Storage structure initialized with subdirectories: `objects/`, `temporary/`, `user-files/`, `movies/`, `public/`, `store-assets/`, `thumbnails/`.
  - [x] Content-addressed physical storage with two-level hexadecimal directories (`data/files/objects/ab/cd/<sha256>`). Filesystem paths strictly hidden behind internal IDs (Rule 10).
  - [x] SQLite schema & migrations (`bigstore/migrations/001_initial.sql`): `storage_accounts`, `directories`, `files`, `uploads`, `upload_chunks`, `storage_events`.
  - [x] Account quota management (`bigstore/src/quota.js`):
    - [x] Default 50 GiB (`53,687,091,200` bytes) per user.
    - [x] Pre-allocation quota availability checks; exceeds returns HTTP 413 `QUOTA_EXCEEDED`.
    - [x] Atomic usage increments upon file commit and decrements upon deletion with append-only audit events (`storage_events`).
  - [x] Directory hierarchy management (`bigstore/src/directories.js`):
    - [x] Parent-child nested folder paths (`/folder/subfolder`).
    - [x] Recursive folder deletion with storage quota reclamation.
  - [x] Streaming chunked upload engine (`bigstore/src/uploads.js`):
    - [x] Upload initialization returning `upl_...` tokens.
    - [x] Raw binary chunk streaming (`PUT /internal/uploads/:id/chunk/:index`) directly to disk without memory buffering.
    - [x] Chunk ordering verification (chunks 0 to `total_chunks - 1`).
    - [x] Assembly with on-the-fly SHA-256 calculation and verification.
    - [x] Automatic deduplication check against existing content objects.
    - [x] Session cleanup of temporary chunks upon finalization or abort.
  - [x] Streaming download engine (`bigstore/src/streaming.js`):
    - [x] Full binary download with `Content-Disposition`, `Content-Type`, `Content-Length`, `ETag`, `Last-Modified`.
    - [x] HTTP Range requests with HTTP 206 Partial Content support (`Range: bytes=start-end`) for video and audio playback.
  - [x] BigStore internal test suite (`bigstore/tests/core.test.js`): 7/7 tests passing.
- [x] Backend BigStore Client & File Controller (`backend/`):
  - [x] BigStore API client (`backend/src/BigStore/BigStoreClient.php`):
    - [x] Internal communication across `backend_net` with `X-Internal-Service-Token`.
    - [x] Zero client access to BigStore (Rule 3 & 4).
    - [x] Non-buffering direct proxy streaming with HTTP Range forwarding (`streamFile()`).
  - [x] File Controller (`backend/src/Controllers/FileController.php`):
    - [x] Authentication and ownership verification (`owner_type = 'user'`, `owner_id = $userId`).
    - [x] Cross-user isolation (User B cannot access or manipulate User A's files).
    - [x] Quota reporting (`GET /api/v1/storage/quota`).
    - [x] Directory CRUD (`POST /api/v1/directories`, `DELETE /api/v1/directories/{id}`).
    - [x] Chunked upload endpoints (`/api/v1/files/upload/init`, `/chunk/{index}`, `/finalize`).
    - [x] Direct single-step multipart upload (`POST /api/v1/files/upload`).
    - [x] File metadata retrieval (`GET /api/v1/files/{id}`).
    - [x] Direct file download (`GET /api/v1/files/{id}/download`).
    - [x] HTTP Range stream proxy (`GET /api/v1/files/{id}/stream`).
    - [x] Soft delete with quota reclaim (`DELETE /api/v1/files/{id}`).
  - [x] Route registration in `backend/public/index.php`.
- [x] Frontend Webapp File Explorer UI (`webapp/`):
  - [x] Modern dark glassmorphic file explorer (`webapp/src/js/pages/files.js`).
  - [x] Visual storage quota progress bar (percentage, used / total GiB).
  - [x] Interactive breadcrumb navigation.
  - [x] Drag & drop file dropzone with visual hover states.
  - [x] Client-side chunked streaming upload engine (splits large files into 2 MiB chunks and uploads sequentially with real-time progress bar).
  - [x] File list with file type badges, formatted sizes, timestamps, download and delete buttons.
  - [x] Media preview modal supporting video and audio streaming and image previews.
  - [x] Stylesheet (`webapp/src/css/files.css`) integrated and compiled into Webpack bundle.
  - [x] Storage navigation link added to header and console account page.
- [x] Automated Test Suites & Regression Verification:
  - [x] `bigstore/tests/core.test.js`: 7/7 passed.
  - [x] `scripts/test-storage.sh`: 17/17 passed.
  - [x] `scripts/test-landing.sh`: 17/17 passed.
  - [x] `scripts/test-auth.sh`: 10/10 passed.
  - [x] `scripts/test-health.sh`: 8/8 passed.
  - [x] Total: **59 automated checks passing with 0 failures**.

---

## 7. Fee Structure & Subscription Pricing Rules (Spec Addition)

In accordance with financial integrity rules (Rule 6: integer minor units only, Rule 7: ledger mutation required):

1. **Subscribers**:
   - **Monthly Subscription Fee**: **3.00€ / month** (`300` cents).
   - **Benefits**:
     - 50 GiB storage quota on BigStore.
     - **0.00€ platform fee** on store orders and checkout transactions.
   - **Billing**: Recorded as a `SUBSCRIPTION_RENEWAL` billing entry (`amount_cents = 300`) accumulating against the associated partner debt ledger.

2. **Non-Subscribers**:
   - **Platform Fee**: **1.00€** (`100` cents) platform fee charged per checkout order/transaction.
   - **Enforcement**: Server-side checkout calculation in Phase 11 (`amount_cents + 100` cents platform fee). Cannot be bypassed by frontend tampering (Rule 11).
   - **Ledger Audit**: Platform fee recorded as a separate ledger line item or breakdown on order creation.

---

## 8. Stopped / Next Steps

- **Where We Stopped**: Completed and verified Phase 4 (BigStore Core Storage Engine & Streaming Uploads). All 59 tests across health, auth, landing, bigstore, and storage pass cleanly.
- **Next Phase**: **Phase 5 — Subscriptions & Storage Quota Assignment**.
  - Scope:
    - Subscriptions database schema & migrations in Backend SQLite (`subscriptions`, `subscription_requests`, `subscription_history`).
    - Customer subscription request workflow (`POST /api/v1/subscriptions/request`).
    - Partner & Admin approval/rejection workflows (`POST /api/v1/subscriptions/{id}/approve`, `POST /api/v1/subscriptions/{id}/reject`).
    - Status lifecycle (`PENDING`, `ACTIVE`, `REJECTED`, `EXPIRED`, `CANCELLED`).
    - Quota synchronization with BigStore (activating 50 GiB quota upon active subscription, restricting when expired/cancelled).
    - Recurring monthly subscription charge: **3.00€ / month** (`300` cents) accumulating against partner debt ledger.
    - Zero platform fee exemption eligibility flag for active subscribers.
    - Frontend subscription management UI in Webapp (Customer subscription request button, Partner/Admin request review table).
    - Automated test suite `scripts/test-subscriptions.sh`.


