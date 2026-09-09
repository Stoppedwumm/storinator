# AGENTS.md — Development Rules & Operating Principles

This document defines the mandatory rules and architectural guidelines for all agents collaborating on this platform.

---

## 1. Mandatory Operating Rules

1. **Read `PROJECT_PLAN.md` before making architectural changes.** Always verify current phase objectives and boundaries.
2. **Keep `webapp`, `backend`, and `bigstore` separated.** Maintain clean logical and physical separation between services.
3. **Browser never directly accesses BigStore.** All client communication routes through the backend.
4. **BigStore is internal-only.** It must never be exposed directly to the public internet or external ports.
5. **Frontend cannot enforce authorization.** The backend is the single source of truth for authorization.
6. **Never use floating-point money.** Always store monetary amounts as integer minor units (cents/cents equivalent).
7. **Every money change requires a ledger entry.** Append-only ledger transactions must record every balance mutation.
8. **Every partner debt change requires a billing entry or payment entry.** Maintain complete audit trails for partner accounting.
9. **Never store plaintext passwords.** Use Argon2id via `password_hash()` and `password_verify()`.
10. **Never expose filesystem paths.** Use generated IDs (e.g., UUIDv7, prefixed tokens) and internal storage hashes.
11. **Never trust frontend price/balance/permission values.** Validate all quantities, prices, balances, and permissions server-side.
12. **All uploads must be securely validated.** Validate size, quota, server-detected MIME type, and SHA-256 checksums.
13. **All sensitive actions require authorization checks.** Implement centralized authorization checks with ownership verification.
14. **Add migrations for database changes.** Use sequential, numbered SQL migration files. Never create tables ad-hoc at runtime.
15. **Add tests for important behavior.** Maintain test coverage across authentication, authorization, storage, and commerce.
16. **Do not silently change API contracts.** Coordinate contract updates between backend and frontend.
17. **Run relevant tests before completing a task.** Verify that all unit, integration, and health tests pass.
18. **Verify the affected UI in the browser when UI changes are made.** Ensure responsive behavior and visual fidelity.
19. **Do not consider a task complete merely because it compiles.** Verify execution, logs, and edge cases.
20. **Record unfinished items clearly.** Document pending work, known constraints, and next steps in `PROJECT_PLAN.md`.

---

## 2. Agent Roles and Ownership

* **Architect Agent**: Owns cross-service design, schemas, API contracts, and architecture review.
* **Frontend Agent**: Owns `webapp/` (HTML5, CSS3, vanilla JavaScript, Webpack, responsive layouts, UI state).
* **Backend Agent**: Owns `backend/` (PHP 8.4+, Composer, REST API, SQLite PDO, auth, permissions, business logic).
* **BigStore Agent**: Owns `bigstore/` (Node.js, Express, better-sqlite3, streaming, chunked uploads, quotas).
* **Store/Commerce Agent**: Owns logical design and financial integrity for wallet, billing, stores, checkout, orders.
* **QA Agent**: Owns integration tests, regression tests, edge-case validation, financial race condition tests.
* **Security Agent**: Reviews authentication, authorization, file validation, rate limiting, and CSRF/XSS defenses.
* **DevOps Agent**: Owns Dockerfiles, Docker Compose, container networking, health checks, backup/restore scripts.

---

## 3. Definition of Done

A task or feature is complete only if:
- Implementation exists
- Database migrations exist
- Authorization exists
- Validation exists
- Error handling exists
- Tests exist
- Relevant documentation exists
- Docker build still works
- Browser/API behavior has been verified
