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
| **Phase 3** | Landing Page | Corporate minimal teaser website, secret access code input in search bar, server-side code validation, session unlock to login. | Up Next |
| **Phase 4** | BigStore Core | Physical hashed storage paths, directory trees, file metadata, chunked streaming uploads, checksums, quota validation. | Pending |
| **Phase 5** | Subscriptions | 50 GiB storage quota assignment, request/approval/reject workflow, expiration dates; **Subscription pricing: 3.00€/month (300 cents)** billed against partner; platform fee exemption. | Pending |
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

## 5. Fee Structure & Subscription Pricing Rules (Spec Addition)

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

## 6. Stopped / Next Steps

- **Where We Stopped**: Completed and verified Phase 2 (Authentication & Authorization). Updated implementation plan with 1€ non-subscriber fee and 3€/mo subscription fee.
- **Next Phase**: **Phase 3 — Landing Page & Secret Teaser Access Code**.
  - Scope: Polished corporate minimalist teaser website, secret access code input in search bar (`anticipation2026`), server-side code validation, session unlock transition to dashboard/login, responsive design.

