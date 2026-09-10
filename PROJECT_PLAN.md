# File Sharing / Store Platform — Master Project Plan

## 1. System Architecture Overview

```text
                              Public Internet
                                     |
                                     v
             +--------------------------------------------------+
             |            Webapp Container (Nginx + PHP)        |
             |  - Static Assets (Webpack Bundle, HTML5/CSS/JS)  |
             |  - High-performance FastCGI route for /api/*    |
             |  - PHP 8.4-FPM Execution Engine                  |
             |  - Centralized Auth & Business Rules (backend/)  |
             |  - SQLite Database (/data/backend.sqlite)       |
             |  - Port 80 (external: ${PORT:-8080})            |
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
             |  - SQLite Database (/data/databases/bigstore.db)|
             +--------------------------------------------------+
```

### Architecture Decision: Webapp & Backend Container Consolidation
- **Decision (Executive Directive):** Consolidate the PHP backend runtime into the `webapp` container alongside Nginx and PHP 8.4-FPM, processing `/api/*` requests locally over FastCGI (`127.0.0.1:9000`).
- **Rationale:** Resolves Docker multi-bridge network race conditions, eliminates cross-container DNS and TCP connection timeouts, and delivers sub-millisecond API execution while preserving codebase cleanliness (`webapp/` for frontend SPA, `backend/` for PHP business logic).
- **Network Isolation Policy:**
  - **External -> Webapp**: Host port mapped to Nginx (`${PORT:-8080}:80`).
  - **Webapp Internal**: Nginx talks to PHP 8.4-FPM over FastCGI loopback (`127.0.0.1:9000`).
  - **Backend -> BigStore**: Routed internally via unified container network `platform_net` using authenticated service secret tokens. BigStore publishes zero host port mappings and is strictly shielded from external access.

---

## 2. Phase-by-Phase Roadmap

| Phase | Milestone | Scope / Key Deliverables | Status |
|:------|:----------|:-------------------------|:-------|
| **Phase 1** | **Foundation** | Repo structure, Dockerfiles, Docker Compose, Webpack setup, minimal PHP backend, minimal BigStore service, separate SQLite DBs, internal network isolation, health endpoints, reverse-proxy routing, migration runners, health tests, README. | **COMPLETED** |
| **Phase 2** | **Authentication** | Users, Roles (CUSTOMER, PARTNER, ADMIN), Argon2id passwords, Sessions (SHA-256 tokens), centralized AuthMiddleware & RoleMiddleware, brute-force rate limiter, test accounts seed, test suite. | **COMPLETED** |
| **Phase 3** | **Landing Page** | Corporate minimal teaser website, secret access code input in search bar, server-side code validation, rate limiter, session unlock to login. | **COMPLETED** |
| **Phase 4** | **BigStore Core** | Physical hashed storage paths, directory trees, file metadata, chunked streaming uploads, checksums, quota validation, HTTP Range streaming, file manager UI. | **COMPLETED** |
| **Phase 5** | **Subscriptions** | 50 GiB storage quota assignment, request/approval/reject workflow, expiration dates; **Subscription pricing: 3.00€/month (300 cents)** billed against partner; platform fee exemption; automated test suite. | **COMPLETED** |
| **Phase 6** | **Wallet & Ledger** | Integer cents balance, append-only transaction ledger, atomic top-up, partner top-up balance allocation, concurrency safeguards. | **COMPLETED** |
| **Phase 7** | **Partner Billing** | Partner debt accumulation from top-ups and renewals, admin partial/full debt payment settlement, immutable billing ledgers. | **COMPLETED** |
| **Phase 8** | **File Sharing** | Random token share URLs (/s/{token}), download permissions, password protection, view counters, expiration dates. | **In Progress** |
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

## 8. Phase 5 Detailed Deliverables & Checklist (Subscriptions & Storage Quota Assignment)

- [x] Subscriptions Database Schema & Migrations (`backend/migrations/003_subscriptions.sql`):
  - [x] `subscriptions` table tracking active periods, statuses (`ACTIVE`, `CANCELLED`, `EXPIRED`), auto-renewal, and user/partner mappings.
  - [x] `subscription_requests` table with lifecycle states (`REQUESTED`, `APPROVED`, `REJECTED`), rejection reasons, resolved timestamps, and admin/partner actor IDs.
  - [x] `subscription_events` audit table recording state transitions.
  - [x] `partner_billing_entries` integration recording 300 minor cents per subscription activation/renewal against partner debt ledger.
- [x] Backend Subscription Service & Controller (`backend/src/Services/SubscriptionService.php`, `backend/src/Controllers/SubscriptionController.php`):
  - [x] Customer subscription request endpoint (`POST /api/v1/subscriptions/request`) with duplicate pending request prevention (HTTP 409 Conflict).
  - [x] Current subscription and fee-exemption query (`GET /api/v1/subscriptions/current`).
  - [x] Customer subscription self-cancellation (`POST /api/v1/subscriptions/cancel`).
  - [x] Partner & Admin pending requests query (`GET /api/v1/partner/subscription-requests`).
  - [x] Partner approval endpoint (`POST /api/v1/partner/subscription-requests/{id}/approve`) atomically activating subscription, increasing `partners.debt_cents` by 300 cents, and inserting an immutable `SUBSCRIPTION_RENEWAL` billing entry.
  - [x] Partner rejection endpoint (`POST /api/v1/partner/subscription-requests/{id}/reject`) with optional reason.
  - [x] Partner renewal endpoint (`POST /api/v1/partner/subscriptions/{id}/renew`) extending active period by 30 days and incrementing partner debt by 300 cents.
  - [x] Storage access gate (`backend/src/Controllers/FileController.php`) strictly requiring active subscription for file upload and directory creation, returning HTTP 403 `SUBSCRIPTION_REQUIRED` otherwise.
- [x] Frontend Webapp Subscription Management UI (`webapp/src/`):
  - [x] Customer subscription portal (`webapp/src/js/pages/subscriptions.js`):
    - [x] Real-time subscription status badge (`ACTIVE`, `PENDING`, `INACTIVE`, `CANCELLED`).
    - [x] 50 GiB storage quota allotment indicator and fee exemption highlight (0.00€ vs 1.00€).
    - [x] Direct request button and self-service cancellation with confirmation.
  - [x] Partner & Admin Review Queue:
    - [x] Pending requests table with customer details, requested timestamps, and notes.
    - [x] One-click approval button clearly indicating 3.00€ debt accrual.
    - [x] One-click rejection button prompting for rejection reason.
  - [x] File Manager Integration (`webapp/src/js/pages/files.js`):
    - [x] Direct warning alert when storage actions are blocked due to `SUBSCRIPTION_REQUIRED` with 1-click CTA button redirecting to `#/subscriptions`.
  - [x] Stylesheet (`webapp/src/css/subscriptions.css`) integrated into Webpack bundle with glassmorphic cards and badges.
  - [x] Navigation links in top header and login dashboard.
- [x] Automated Test Suite & Regression Verification:
  - [x] `scripts/test-subscriptions.sh`: 18/18 checks passed.
  - [x] Zero regressions across all prior test suites (`test-health.sh`, `test-auth.sh`, `test-landing.sh`, `test-storage.sh`).

---

## 9. Phase 6 Detailed Deliverables & Checklist (Wallet & Ledger System)

- [x] Database Migrations (`backend/migrations/004_wallets.sql`):
  - [x] `wallets` table storing user balances in integer minor cents (EUR) with timestamps and foreign keys.
  - [x] `wallet_transactions` table enforcing append-only ledger mutations with transaction types (`CUSTOMER_TOPUP`, `PARTNER_TOPUP`, `ORDER_PAYMENT`, `SUBSCRIPTION_PAYMENT`), balance before/after snapshots, and idempotency key constraints.
  - [x] Pre-seeded wallet accounts for default test users.
- [x] Backend Wallet Service & Controller (`backend/src/Services/WalletService.php`, `backend/src/Controllers/WalletController.php`):
  - [x] Strict integer cents accounting (Rule 6). Floating point currency eliminated.
  - [x] Append-only ledger mutations (Rule 7) recording balance snapshots.
  - [x] SQLite WAL mode and immediate atomic locking (`BEGIN IMMEDIATE`) with SQL `RETURNING` clauses preventing race conditions and lost updates under concurrency.
  - [x] Database-level overdraft protection (`WHERE id = :wid AND balance_cents >= :amount`).
  - [x] Customer balance query (`GET /api/v1/wallet`).
  - [x] Paginated transaction history with type filtering (`GET /api/v1/wallet/transactions`).
  - [x] Idempotent direct top-up endpoint (`POST /api/v1/wallet/topup`) supporting `Idempotency-Key` headers.
  - [x] Partner customer credit facility (`POST /api/v1/partner/wallet/credit`) with dual-entry accounting: customer balance credited and partner debt incremented with partner billing entry (Rule 8).
  - [x] Partner customer directory listing with balances (`GET /api/v1/partner/wallet/customers`).
  - [x] Centralized route registration with `AuthMiddleware` in `backend/public/index.php`.
- [x] Frontend Webapp Wallet & Ledger UI (`webapp/`):
  - [x] Responsive dark glassmorphic wallet page (`webapp/src/js/pages/wallet.js`).
  - [x] Live balance hero display with formatted minor cents.
  - [x] Instant top-up form with preset buttons (+5€, +10€, +20€, +50€) and custom euro inputs.
  - [x] Partner customer credit panel for `PARTNER` / `ADMIN` roles.
  - [x] Paginated audit ledger table with colored amount indicators and transaction badges.
  - [x] Custom styling (`webapp/src/css/wallet.css`) compiled into Webpack bundle.
  - [x] "Wallet" navigation link added to top header (`webapp/src/js/components/header.js`).
  - [x] Route registration in SPA router (`webapp/src/js/app.js`).
- [x] Automated Test Suite & Regression Verification:
  - [x] `scripts/test-wallet.sh`: 37/37 automated checks passing:
    - Unauthenticated 401 rejection on wallet and topup endpoints.
    - Initial 0.00€ balance for new customer accounts.
    - Input validation rejecting negative, zero, and out-of-bounds amounts (HTTP 422).
    - Customer topup balance increments and ledger transaction snapshots.
    - Idempotency key replay test preventing duplicate balance crediting.
    - Ledger transaction history pagination with limit and offset.
    - Role-based authorization: customer forbidden from partner credit endpoint (HTTP 403).
    - Partner credit execution: customer balance increment, partner debt increment, and partner billing entry creation.
    - Partner customer search and directory listing with balances.
    - Wallet Service overdraft protection returning HTTP 402 Insufficient Funds.
    - High-concurrency race condition test verifying zero lost updates across concurrent requests.
  - [x] Zero regressions across all prior test suites: **107 passing checks across 6 test suites**.

---

## 10. Phase 7 — Partner Billing & Debt Settlement (IN PROGRESS)

### Work Completed in Phase 7:
- [x] **Database Schema & Migrations (`backend/migrations/005_partner_billing.sql`)**:
  - Enhanced `partner_payments` with `payment_method VARCHAR(64) DEFAULT 'MANUAL'`, `reference_number VARCHAR(128)`, `debt_after_cents INTEGER DEFAULT 0`, and `idempotency_key VARCHAR(128)`.
  - Created `partner_statements` table (`id`, `partner_id`, `statement_period`, `opening_debt_cents`, `total_charges_cents`, `total_payments_cents`, `closing_debt_cents`, `status`, `notes`, `generated_at`).
  - Added indexes: `idx_pbe_partner_created`, `idx_pbe_operation`, `idx_ppay_partner_created`, `idx_ppay_idempotency`, and `idx_pstm_partner`.
  - Applied via `php backend/bin/migrate.php` inside webapp container.
- [x] **Backend Service Layer (`backend/src/Services/PartnerBillingService.php`)**:
  - Implemented `getBillingSummary(partnerId)` computing total charges, total payments, calculated debt, and returning recent entries and payments.
  - Implemented `getBillingEntries(partnerId, limit, offset, opType)` with pagination and integer cents formatting (`+X.XX €`).
  - Implemented `getPayments(partnerId, limit, offset)` with pagination, admin attribution, payment method, reference number, and `-X.XX €` formatting.
  - Implemented `settlePayment(adminUserId, partnerId, amountCents, ...)` concurrency-hardened with `BEGIN IMMEDIATE`, atomic `UPDATE partners SET debt_cents = debt_cents - :amount WHERE id = :pid AND debt_cents >= :amount RETURNING debt_cents`, overpayment prevention (HTTP 422), cursor closure, and idempotency key caching.
  - Implemented `updateInvoiceRetention(partnerId, enabled)` toggling partner invoice retention setting.
  - Implemented `generateStatement(partnerId, period, notes)` and `getStatements(partnerId)` recording immutable accounting statements.
  - Implemented `listAllPartners(limit, offset, search)` for administrative overview.
- [x] **Backend Controller Layer (`backend/src/Controllers/PartnerBillingController.php`)**:
  - `GET /api/v1/partner/billing` (Partner or Admin).
  - `GET /api/v1/partner/billing/entries` (Partner or Admin).
  - `GET /api/v1/partner/billing/payments` (Partner or Admin).
  - `GET /api/v1/partner/billing/statements` (Partner or Admin).
  - `POST /api/v1/partner/billing/statements/generate` (Partner or Admin).
  - `POST /api/v1/partner/settings/invoice-retention` (Partner or Admin).
  - `GET /api/v1/admin/billing/partners` (Admin only).
  - `POST /api/v1/admin/billing/settle` (Admin only, supports idempotency key and overpayment protection).
- [x] **Route Registration (`backend/public/index.php`)**:
  - Registered all Partner Billing endpoints under both `/api/v1/...` and `/api/...` prefixes protected by `AuthMiddleware`.
- [x] **Automated Test Suite (`scripts/test-billing.sh`)**:
  - **30/30 tests passed**:
    - Unauthenticated request rejection (HTTP 401).
    - Customer role access rejection (HTTP 403 FORBIDDEN).
    - Partner billing overview and debt calculation verification.
    - Invoice retention toggle verification (true/false).
    - Partner billing entries ledger pagination and positive charge formatting.
    - Admin partner debt list query.
    - Settlement validation (zero amount, negative amount, overpayment exceeding debt).
    - Partial debt settlement: debt decrements, `partner_payments` recorded with method and reference, historical billing entries strictly preserved (Acceptance 22).
    - Idempotency replay check: identical request returns `idempotent_replay: true` without deducting debt again.
    - Statement generation and statement listing verification.

---

- [x] **Frontend Integration & UI Components**:
  - Implemented `ApiClient` methods in `webapp/src/js/api.js`: `getPartnerBilling`, `getPartnerBillingEntries`, `getPartnerPayments`, `getPartnerStatements`, `generatePartnerStatement`, `updateInvoiceRetention`, `getAdminPartnersBilling`, and `adminSettlePartnerDebt`.
  - Built responsive dark glassmorphism stylesheet `webapp/src/css/billing.css` with metric cards (debt, charges, payments, retention toggle), tabbed tables, status badges, and admin settlement controls.
  - Implemented comprehensive billing portal `webapp/src/js/pages/billing.js` with live metrics, charges ledger pagination, payments ledger pagination, statement generation, invoice retention switch, and admin settlement form.
  - Added `/billing` route to `webapp/src/js/app.js` and "Billing" link to `webapp/src/js/components/header.js` for `PARTNER` and `ADMIN` users.
  - Compiled production bundle with Webpack, synced to host volume, and verified in browser.
- [x] **Full Regression & Integrity Testing**:
  - Verified all 7 test suites pass with zero failures: **137 passing checks across 7 test suites** (`test-health.sh`, `test-landing.sh`, `test-auth.sh`, `test-storage.sh`, `test-subscriptions.sh`, `test-wallet.sh`, `test-billing.sh`).

---

## 11. Phase 8 — File Sharing (IN PROGRESS)

### Phase Objectives:
Build secure, tokenized public file and directory sharing adhering strictly to Rule 10 (never expose raw BigStore or filesystem paths) and Rule 13 (centralized authorization):
1. **Cryptographically Secure Tokens**: Share links use URL-safe random tokens (`/s/{token}`) generated server-side.
2. **Access Control & Permissions**:
   - `download_enabled`: Toggle allowing or forbidding direct file download (view/preview only vs downloadable).
   - `password_hash`: Optional Argon2id password protection requiring password unlock before granting file access or metadata.
   - `expires_at`: Optional timestamp expiration; access past expiration returns HTTP 410 Gone.
   - `max_downloads`: Optional download quota; exceeding download limit returns HTTP 410 Gone.
3. **Audit & Counters**:
   - `view_count`: Incremented on each share landing page access.
   - `download_count`: Incremented atomically on each successful file download stream.
   - `is_revoked`: Instant revocation by the file owner or admin.
4. **Zero Path Leakage**:
   - Downloads stream through backend proxy via BigStore internal service using hashed file IDs.
   - BigStore remains internal-only; client never accesses BigStore directly (Rules 3 & 4).
5. **UI & Deliverables**:
   - Share modal in File Manager to generate links with password, expiry, and download toggles.
   - Public share page (`/s/{token}` or `#/s/:token`) with file details, password prompt modal, preview/download actions.
   - Shares management interface in webapp.
   - Automated test suite `scripts/test-sharing.sh`.



