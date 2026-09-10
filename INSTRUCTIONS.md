# File Sharing / Store Platform — Master Development Specification

## 1. Project Objective

Build a self-hosted web platform combining:

* Cloud file storage and file sharing
* Personal storage for subscribed customers
* Movie-library management and streaming
* Automatic movie metadata/poster scraping
* User accounts and role management
* Partner-operated online stores
* Internal account credit/money system
* Partner billing/debt management
* Customer subscription management
* Public file and movie directory
* Administration panel
* Docker-based deployment

The entire application must consist of three primary projects:

```text
/
├── webapp/
├── backend/
├── bigstore/
├── docker-compose.yml
├── .env.example
├── PROJECT_PLAN.md
├── AGENTS.md
└── README.md
```

The services must remain logically separated.

---

# 2. Technology Stack

## webapp

Frontend technologies:

* HTML5
* CSS3
* JavaScript
* Webpack
* No React/Vue/Angular
* Fetch API for backend communication
* History API for SPA-like navigation if desired
* Responsive desktop/mobile interface

Responsibilities:

* User-facing UI
* Authentication screens
* File manager
* Movie library
* Movie player
* Storefronts
* Checkout
* Customer dashboard
* Partner dashboard
* Admin dashboard
* Public directory
* Landing/startup page

The webapp must never communicate directly with the BigStore service.

All application communication goes:

```text
Browser
   |
   v
Backend API
   |
   v
BigStore
```

---

# 3. Backend

Technology:

* PHP 8.4+
* Composer
* REST-style JSON API
* PDO
* Sessions or secure token-based authentication
* Strict input validation
* Centralized authorization middleware

The backend is the authority for:

* Authentication
* Users
* Roles
* Permissions
* Stores
* Products
* Orders
* Internal money accounts
* Billing
* Partner debt
* Subscription management
* File-access authorization
* Movie metadata management
* Public directory
* Administrative actions

The backend must never trust permissions supplied by the frontend.

---

# 4. BigStore

BigStore is a private internal storage service.

It must NOT be directly accessible from the public internet.

Responsibilities:

* File storage
* Directory structures
* File metadata
* Storage quotas
* SQLite databases
* File integrity
* Uploads
* Downloads
* HTTP Range streaming
* Thumbnails where applicable
* Media metadata
* Storage accounting
* Deleted-file handling
* Temporary uploads

Suggested implementation:

```text
Node.js
Express/Fastify
better-sqlite3
```

The BigStore service should expose a private internal HTTP API.

Example Docker network:

```text
webapp -> backend -> bigstore
```

Only `backend` may access `bigstore`.

BigStore should not make authorization decisions about users. The backend authorizes an action and sends an authenticated internal request to BigStore.

---

# 5. Docker Architecture

Use Docker Compose.

Required containers:

```text
webapp
backend
bigstore
```

Suggested ports:

```text
webapp:
  80/443 externally

backend:
  internal network only, or routed through /api/

bigstore:
  internal network only
```

Recommended routing:

```text
https://example.com/
    -> webapp

https://example.com/api/*
    -> backend

backend
    -> http://bigstore:8080
```

Volumes:

```text
bigstore-data
bigstore-files
backend-data
```

Example conceptual layout:

```text
volumes/
├── backend/
└── bigstore/
    ├── databases/
    ├── user-files/
    ├── movies/
    ├── public/
    ├── store-assets/
    ├── thumbnails/
    └── temporary/
```

Docker Compose must include:

* Health checks
* Restart policies
* Persistent volumes
* Internal network
* Environment variables
* Development mode
* Production mode

---

# 6. Main Account Types

There are three primary user roles.

## CUSTOMER

Standard end user.

Can:

* Browse permitted stores
* Purchase products
* Maintain internal account balance
* View own transactions
* Request subscriptions
* Use personal file storage when subscribed
* Share files
* Browse public directory
* Stream accessible movies
* View order history
* Download invoices/receipts when available

---

## PARTNER

Business/store operator.

Can:

* Perform normal customer functionality where appropriate
* Create stores
* Manage stores
* Create products
* Upload product images
* Create categories
* Process purchases
* Accept platform account balance
* Create local/regular store accounts
* Approve subscription requests
* Renew subscriptions
* Add money/credits to customer accounts
* View own billing/debt
* Configure invoice retention
* Manage store employees/accounts
* View store orders
* View store analytics

Partners must never gain global administrative access.

---

## ADMIN

Full platform administrator.

Can:

* Manage users
* Create/disable partners
* Change roles
* Suspend accounts
* Manage subscriptions
* Inspect partner billing
* Mark partner debt as paid
* Manage public directory
* Add movies
* Manage movies
* Manage storage
* Inspect system health
* Inspect audit logs
* Configure platform settings
* Moderate stores
* Disable stores
* Manage metadata providers

---

# 7. Internal Money/Credit System

The platform contains its own account balance system.

Do NOT represent balances using floating-point numbers.

Store monetary values as integer minor units:

```text
1234 = 12.34 credits/euros
```

Use something like:

```text
balance_cents INTEGER
```

Prefer an append-only ledger instead of modifying balances without history.

Tables/concepts:

```text
wallets
wallet_transactions
```

Transaction types:

```text
PARTNER_TOPUP
STORE_PURCHASE
REFUND
ADMIN_ADJUSTMENT
SUBSCRIPTION_RENEWAL
TRANSFER
REVERSAL
```

Every balance change must create a ledger entry.

Example:

```text
Customer balance before: 5000
Purchase:                 -1299
Customer balance after:   3701
```

Never allow:

* Double spending
* Negative balance unless specifically enabled
* Duplicate transaction processing
* Client-supplied final balances

Every financial operation must be atomic.

---

# 8. Partner Top-Up System

Partners may add money to customer accounts.

Example:

```text
Customer:
Max

Current balance:
€20

Partner adds:
€50

New customer balance:
€70
```

The customer immediately receives the credit.

However, the partner now owes the platform:

```text
€50
```

Therefore create:

```text
partner_debt + €50
customer_wallet + €50
```

Both changes must occur in a single database transaction.

The partner must see this on their bill.

---

# 9. Partner Billing

Partners accumulate debt through actions such as:

* Customer account top-ups
* Subscription renewals
* Other administrator-configured billable operations

Example:

```text
Partner invoice

Customer credit           €50
Subscription renewal      €10
Subscription renewal      €10
--------------------------------
Outstanding               €70
```

Administrators can mark part or all of the debt as paid.

Example:

```text
Outstanding:
€70

Admin payment:
€50

Remaining:
€20
```

Never delete the original billing entries.

Instead use a ledger.

Suggested objects:

```text
partner_billing_entries
partner_payments
partner_statements
```

---

# 10. Invoice Retention Setting

Each partner/store can choose whether customer invoices are permanently stored.

Setting:

```text
invoice_retention_enabled
```

If enabled:

* Generate invoice record
* Save immutable invoice snapshot
* Make it available to customer
* Make it available to partner

If disabled:

* Generate transaction/order confirmation
* Do not retain a permanent full invoice beyond legally/technically required transaction data

Do not delete essential accounting or transaction records merely because invoice retention is disabled.

---

# 11. Subscriptions

Customers may request subscriptions.

Subscriptions provide:

```text
50 GB personal storage
```

Subscription states:

```text
NONE
REQUESTED
ACTIVE
EXPIRED
REJECTED
SUSPENDED
```

Flow:

```text
Customer
    |
    v
Request subscription
    |
    v
Partner sees request
    |
    +-- approve
    |
    +-- reject
```

Upon approval:

```text
subscription_start
subscription_end
storage_quota = 50 GB
```

Partners can renew active or expired subscriptions.

A renewal:

1. Extends expiration date
2. Creates billing entry against partner
3. Creates audit-log entry
4. Notifies customer

Example:

```text
Current expiry:
2026-12-31

Renewal:
1 month

New expiry:
2027-01-31
```

Renewal durations must be configurable.

---

# 12. Storage Quotas

Subscribed customers receive exactly:

```text
50 GiB
```

Use bytes internally:

```text
53687091200
```

Track:

```text
quota_bytes
used_bytes
```

Quota must include all active files belonging to the customer.

Upload must be rejected if:

```text
used_bytes + incoming_file_size > quota_bytes
```

Storage usage should be recalculated periodically as an integrity check.

---

# 13. File Manager

Subscribed customers receive a cloud-drive style interface.

Features:

* Create folder
* Rename folder
* Delete folder
* Upload files
* Drag-and-drop upload
* Multi-file upload
* Download
* Rename
* Move
* Copy
* Delete
* Restore deleted files if trash enabled
* File previews
* Sort
* Search
* Breadcrumb navigation
* Storage usage indicator

Possible layout:

```text
My Files

Storage:
12.4 GB / 50 GB

+ Upload
+ New Folder

Documents
Movies
Pictures
Shared

Filename                 Size       Modified
------------------------------------------------
holiday.mp4              2.4 GB     Today
school.pdf               4 MB       Yesterday
photo.jpg                3 MB       Yesterday
```

---

# 14. File Sharing

Users can create share links.

Share options:

```text
public/private
expiry date
download enabled
password protection
maximum downloads
```

Example URL:

```text
/s/7bcPdsA2
```

Never expose raw BigStore paths.

Share IDs must use cryptographically secure random tokens.

Optional features:

* Revoke link
* View count
* Download count
* Share expiration
* Password
* Folder sharing

---

# 15. File Upload Architecture

Uploads should not load entire files into memory.

Support:

* Streaming uploads
* Large files
* Resumable/chunked uploads
* File-size validation
* Quota validation
* SHA-256 checksum
* Temporary upload state

Suggested flow:

```text
POST /api/uploads

-> returns upload_id

PUT /api/uploads/{id}/chunks/{chunk}

POST /api/uploads/{id}/complete
```

BigStore combines chunks after completion.

---

# 16. Movie Mode

Folders may be designated as:

```text
movie folders
```

Movie Mode automatically detects media files.

Supported examples:

```text
.mp4
.mkv
.webm
.mov
.m4v
```

Example filenames:

```text
Interstellar (2014).mkv
Cars.2006.1080p.mkv
The Matrix (1999)/movie.mkv
```

BigStore scans media files.

Backend manages metadata.

---

# 17. Movie Metadata Scraping

Use legitimate metadata providers such as:

* TMDB
* OMDb
* configurable metadata API

Metadata may include:

```text
title
original_title
release_year
description
poster
backdrop
genres
runtime
cast
director
rating
external IDs
```

Do not scrape arbitrary websites when a supported metadata API is available.

Matching process:

```text
Filename
   |
   v
Normalize filename
   |
   v
Extract title/year
   |
   v
Search metadata provider
   |
   v
Confidence ranking
   |
   +-- high confidence -> automatically assign
   |
   +-- uncertain -> admin/manual selection
```

Administrators must be able to correct metadata.

---

# 18. Movie Library

Movie folders should be displayed like a streaming service.

Example:

```text
Movies

[poster] [poster] [poster] [poster]
Movie A  Movie B  Movie C  Movie D
```

Movie page:

```text
Backdrop

TITLE
2024 • 2h 13m • Action

Description...

[Play]
[Download if permitted]

Cast
Director
Metadata
```

---

# 19. Streaming

BigStore must implement HTTP Range requests.

Required headers include:

```text
Accept-Ranges: bytes
Content-Range
Content-Length
Content-Type
```

The browser must therefore support:

* Seeking
* Resume
* Partial loading

Do not pipe the entire movie through PHP memory.

Preferred architecture:

```text
Browser
   |
   v
Backend authorization
   |
   v
short-lived stream token
   |
   v
authorized streaming endpoint
   |
   v
BigStore
```

The token should expire quickly.

Example:

```text
/api/media/stream/{token}
```

---

# 20. Optional Transcoding

Original files should initially use direct play where browser-compatible.

Later add optional FFmpeg support.

Possible modes:

```text
DIRECT_PLAY
TRANSCODE
HLS
```

HLS:

```text
master.m3u8
720p
1080p
```

Transcoding must be optional because it can require substantial CPU resources.

---

# 21. Public Directory

Administrators can populate a publicly visible directory.

Directory objects may include:

* Files
* Folders
* Movies
* Documents
* Collections

Example:

```text
Public Directory

Movies
Documents
Downloads
Collections
```

Administrators can:

* Add item
* Remove item
* Reorder item
* Feature item
* Hide item
* Add description
* Add custom cover
* Set visibility

Public movie entries use Movie Mode.

---

# 22. Store System

Partners can create one or more online stores.

A store should function much more like a complete e-commerce storefront than a simple product list.

Store properties:

```text
name
slug
description
logo
banner
theme
custom images
contact details
terms
categories
settings
invoice settings
status
```

Example:

```text
/store/techworld
```

No customer subscription is required to access stores.

Normal customer accounts may shop there regardless of storage subscription state.

---

# 23. Store Customization

Partners should be able to customize:

* Logo
* Banner
* Hero images
* Store description
* Categories
* Homepage sections
* Featured products
* Product collections
* Store colors
* Custom pages
* Contact information

Store asset files live inside BigStore:

```text
/store-assets/{store-id}/
```

Validate all uploaded images.

---

# 24. Products

Products need:

```text
id
store_id
name
slug
description
short_description
price
currency
inventory
SKU
status
created_at
updated_at
```

Product features:

* Multiple images
* Variants
* Stock
* Categories
* Tags
* Sale price
* Featured flag
* Digital or physical type
* Custom metadata

Product statuses:

```text
DRAFT
ACTIVE
OUT_OF_STOCK
HIDDEN
ARCHIVED
```

---

# 25. Product Variants

Example:

```text
T-Shirt

Size:
S
M
L
XL

Color:
Black
White
```

Each combination can have:

```text
SKU
price override
stock
image
```

---

# 26. Shopping Cart

Customers can:

* Add item
* Remove item
* Change quantity
* Select variation
* View subtotal
* Checkout

Cart persists for logged-in users.

---

# 27. Checkout

Store purchases use customer account balance.

Example:

```text
Account balance:
€100

Order:
€29.99

Remaining:
€70.01
```

Checkout transaction:

1. Lock wallet
2. Verify product
3. Verify inventory
4. Verify current price
5. Verify balance
6. Deduct balance
7. Create order
8. Reduce stock
9. Credit appropriate store accounting ledger
10. Generate invoice/receipt according to settings
11. Commit transaction

Never trust cart prices sent by frontend.

---

# 28. Orders

Order states:

```text
PENDING
PAID
PROCESSING
SHIPPED
COMPLETED
CANCELLED
REFUNDED
PARTIALLY_REFUNDED
```

Order stores immutable product snapshots.

This prevents old orders changing when a product name or price changes later.

Example:

```text
order_items

product_id
product_name_snapshot
variant_snapshot
unit_price_snapshot
quantity
```

---

# 29. Partner Store Accounts

Partners can create regular accounts associated with their stores.

These accounts are NOT global partners.

Possible roles:

```text
STORE_OWNER
STORE_MANAGER
STORE_STAFF
STORE_SUPPORT
```

Permissions should be configurable.

Examples:

STORE_OWNER:

```text
everything inside store
```

STORE_MANAGER:

```text
products
orders
customers
analytics
```

STORE_STAFF:

```text
orders
inventory
```

STORE_SUPPORT:

```text
orders
customer communication
```

A store account must never be able to modify another store.

---

# 30. Authentication

Login:

```text
email/username
password
```

Passwords:

* Argon2id
* PHP password_hash()
* PHP password_verify()

Do not store plaintext passwords.

Session cookies:

```text
HttpOnly
Secure
SameSite=Lax or Strict
```

Implement:

* Login
* Logout
* Session expiry
* Password change
* Password reset
* Account disable
* Login rate limiting

Optional later:

* TOTP 2FA
* Passkeys

---

# 31. Startup / Landing Page

The public homepage must NOT immediately reveal the application.

It should resemble a startup/company website.

Style:

* Minimal
* Corporate
* Modern
* Slightly mysterious
* Not obviously a file-hosting login page

Content examples:

```text
Building the infrastructure
for what comes next.

Secure infrastructure.
Connected experiences.
One platform.

Launching soon.
```

Include corporate-style sections such as:

```text
Infrastructure
Platform
Security
Partners
Technology
Coming Soon
```

---

# 32. Hidden Login Access

The page contains something visually similar to a search field.

Example:

```text
Search our platform...
```

Typing the configured access code and submitting reveals/redirects to login.

Example configuration:

```env
ENTRY_CODE=...
```

The code must be validated server-side.

Do NOT embed the actual secret code inside frontend JavaScript.

Suggested flow:

```text
POST /api/entry-code

{
    "code": "..."
}
```

If correct:

```text
set short-lived entry cookie
redirect /login
```

The entry code is obscurity, not authentication.

Actual account login is still required.

Rate-limit entry attempts.

---

# 33. Customer Dashboard

Dashboard:

```text
Welcome back, Max

Account balance
€125.50

Subscription
Active until 31 December 2026

Storage
14.2 GB / 50 GB

Recent files

Recent purchases

Public directory
```

---

# 34. Partner Dashboard

Dashboard should show:

```text
Outstanding balance
€420

Customers credited
123

Subscription renewals
52

Stores
3

Orders today
18

Revenue
...
```

Sections:

```text
Overview
Stores
Products
Orders
Customers
Subscriptions
Top-ups
Billing
Employees
Settings
```

---

# 35. Admin Dashboard

Sections:

```text
Overview

Users
Customers
Partners

Subscriptions

Partner billing
Payments

Stores
Store moderation

Files
Storage

Movies
Metadata

Public directory

System
Audit logs
Configuration
```

Dashboard statistics:

```text
total users
active subscriptions
used storage
movie count
store count
orders
partner debt
```

---

# 36. Notifications

Create an internal notification system.

Notification types:

```text
SUBSCRIPTION_APPROVED
SUBSCRIPTION_REJECTED
SUBSCRIPTION_EXPIRING
SUBSCRIPTION_RENEWED

BALANCE_ADDED

ORDER_CREATED
ORDER_SHIPPED
ORDER_REFUNDED

PARTNER_BILLING_ENTRY
PARTNER_PAYMENT

FILE_SHARED
```

Later support:

* Email
* Web push

---

# 37. Suggested Backend Database Model

Backend should use a relational SQLite database initially.

Core tables:

```text
users
roles
user_roles

sessions

wallets
wallet_transactions

subscriptions
subscription_requests
subscription_events

partners
partner_billing_entries
partner_payments

stores
store_users
store_roles
store_permissions

store_assets

products
product_images
product_variants
product_categories
categories

carts
cart_items

orders
order_items
order_events

invoices

notifications

public_directory_items

movies
movie_metadata
movie_cast

entry_codes

audit_logs

settings
```

---

# 38. BigStore Database

Separate SQLite database.

Tables:

```text
files
directories
file_versions

uploads
upload_chunks

storage_accounts

shares
share_access_log

media
media_stream_tokens

thumbnails

file_checksums

trash

storage_events
```

Do not combine this with the backend application database.

---

# 39. File Record

Suggested structure:

```text
files

id
owner_type
owner_id
directory_id
stored_name
original_name
mime_type
size_bytes
sha256
storage_path
created_at
modified_at
deleted_at
```

Never use user-provided filenames as physical storage paths.

Example physical file:

```text
/data/objects/a7/12/a712ea...
```

Database contains:

```text
original_name = holiday.mp4
```

This prevents traversal and filename conflicts.

---

# 40. IDs

Do not expose sequential database IDs unnecessarily.

Use UUIDv7 or secure public IDs.

Example:

```text
usr_...
sto_...
prd_...
ord_...
fil_...
mov_...
shr_...
```

---

# 41. Backend API Structure

Base:

```text
/api/v1/
```

Authentication:

```text
POST /auth/login
POST /auth/logout
POST /auth/password/reset
GET  /auth/me
```

Entry:

```text
POST /entry/verify
```

Users:

```text
GET    /users/me
PATCH  /users/me
GET    /admin/users
GET    /admin/users/{id}
PATCH  /admin/users/{id}
```

Wallet:

```text
GET  /wallet
GET  /wallet/transactions
POST /partner/customers/{id}/credit
```

Subscriptions:

```text
POST /subscriptions/request
GET  /subscriptions/current

GET  /partner/subscription-requests
POST /partner/subscription-requests/{id}/approve
POST /partner/subscription-requests/{id}/reject
POST /partner/subscriptions/{id}/renew
```

Files:

```text
GET    /files
POST   /folders
POST   /uploads
PATCH  /files/{id}
DELETE /files/{id}
GET    /files/{id}/download
```

Shares:

```text
POST   /files/{id}/shares
GET    /shares/{token}
DELETE /shares/{id}
```

Movies:

```text
GET  /movies
GET  /movies/{id}
GET  /movies/{id}/stream
POST /admin/movies/scan
POST /admin/movies/{id}/metadata
```

Stores:

```text
GET    /stores
POST   /partner/stores
GET    /stores/{slug}
PATCH  /partner/stores/{id}
```

Products:

```text
GET    /stores/{store}/products
GET    /stores/{store}/products/{product}
POST   /partner/stores/{id}/products
PATCH  /partner/products/{id}
DELETE /partner/products/{id}
```

Cart:

```text
GET    /cart
POST   /cart/items
PATCH  /cart/items/{id}
DELETE /cart/items/{id}
```

Orders:

```text
POST /checkout
GET  /orders
GET  /orders/{id}
```

Partner billing:

```text
GET /partner/billing
GET /partner/billing/entries
```

Admin billing:

```text
GET  /admin/partners/{id}/billing
POST /admin/partners/{id}/payments
```

Public directory:

```text
GET    /directory
POST   /admin/directory
PATCH  /admin/directory/{id}
DELETE /admin/directory/{id}
```

---

# 42. BigStore Internal API

Require a service authentication secret.

Example:

```text
X-Internal-Service-Token
```

Endpoints:

```text
POST   /internal/files
GET    /internal/files/{id}
DELETE /internal/files/{id}

POST   /internal/uploads
PUT    /internal/uploads/{id}/chunk
POST   /internal/uploads/{id}/complete

GET    /internal/stream/{id}

POST   /internal/shares
DELETE /internal/shares/{id}

GET    /internal/storage/{owner}

POST   /internal/media/scan
```

BigStore should reject any request without valid backend credentials.

---

# 43. Authorization Model

Implement centralized permission checks.

Never scatter simplistic role checks such as:

```php
if ($user['role'] === 'admin')
```

throughout controllers.

Use authorization services such as:

```text
canManageStore()
canManageProduct()
canAccessFile()
canManageSubscription()
canCreditWallet()
canManageBilling()
canManagePublicDirectory()
```

Always verify resource ownership.

Example:

A partner managing:

```text
/store/12
```

must actually be associated with store 12.

---

# 44. Audit Logging

All sensitive operations create audit entries.

Examples:

```text
LOGIN
LOGIN_FAILURE
USER_DISABLED

WALLET_CREDIT
WALLET_ADJUSTMENT

SUBSCRIPTION_APPROVED
SUBSCRIPTION_RENEWED

PARTNER_PAYMENT_RECORDED

STORE_CREATED
PRODUCT_UPDATED

FILE_DELETED

MOVIE_METADATA_CHANGED

ADMIN_SETTING_CHANGED
```

Fields:

```text
actor
action
target_type
target_id
timestamp
IP
metadata
```

Audit logs should be append-only.

---

# 45. Security Requirements

Protect against:

* SQL injection
* XSS
* CSRF
* SSRF
* Path traversal
* IDOR
* Session fixation
* Brute force login
* Unsafe file uploads
* Zip bombs where archives are processed
* MIME-type spoofing
* Malicious SVG uploads
* Excessive file sizes
* Unauthenticated streaming
* Cross-store access
* Wallet race conditions
* Duplicate payments

Use:

```text
PDO prepared statements
Content-Security-Policy
X-Content-Type-Options
Referrer-Policy
CSRF tokens
rate limiting
secure cookies
strict MIME handling
```

Never trust:

```text
user IDs
store IDs
prices
balances
permissions
file paths
quota
```

sent by the browser.

---

# 46. Upload Security

For uploaded files:

* Generate storage filename server-side
* Preserve original filename only as metadata
* Reject path separators
* Detect MIME type server-side
* Limit upload size
* Verify quota
* Scan image dimensions
* Never execute uploaded files
* Serve uploads from a non-executable storage location

Uploaded HTML should normally be downloaded rather than rendered inline.

---

# 47. Movie Security / Content Scope

Movie Mode is a media-management feature.

It should operate on media that the platform operator or users are authorized to store and distribute.

Metadata providers supply descriptive information/posters only.

Movie streaming permissions must follow the same access-control system as other files.

---

# 48. Search

Global search should eventually support:

```text
files
movies
stores
products
public directory
```

Use SQLite FTS5 where useful.

Examples:

```text
movie title
product name
filename
description
```

---

# 49. Background Jobs

Some work must run asynchronously.

Create a lightweight job system for:

* Movie scanning
* Metadata lookup
* Thumbnail generation
* Storage recalculation
* Expired subscriptions
* Expired share links
* Trash cleanup
* Notification dispatch

Can initially use:

```text
jobs table + worker
```

Avoid requiring Redis for version 1.

---

# 50. Scheduled Tasks

Run periodic tasks such as:

Every few minutes:

```text
process jobs
```

Hourly:

```text
expire share links
```

Daily:

```text
check subscriptions
recalculate storage anomalies
cleanup temporary uploads
```

---

# 51. Frontend Design

Visual direction:

* Modern SaaS
* Dark/light-capable architecture
* Minimal borders
* Large cards
* Clean typography
* Generous spacing
* Smooth but restrained animations
* Responsive
* Professional rather than gaming-themed

Components:

```text
Sidebar
Topbar
Cards
Tables
Modal dialogs
Dropdowns
Toast notifications
Tabs
Forms
File grid
Movie poster grid
Store product grid
Charts
```

Do not make every panel visually identical.

Different application areas should retain coherent but distinct layouts.

---

# 52. Frontend Structure

Suggested:

```text
webapp/
├── src/
│   ├── index.html
│   ├── css/
│   │   ├── variables.css
│   │   ├── base.css
│   │   ├── components.css
│   │   ├── landing.css
│   │   ├── dashboard.css
│   │   ├── files.css
│   │   ├── movies.css
│   │   └── stores.css
│   │
│   ├── js/
│   │   ├── app.js
│   │   ├── api.js
│   │   ├── auth.js
│   │   ├── router.js
│   │   ├── state.js
│   │   │
│   │   ├── pages/
│   │   ├── components/
│   │   └── utils/
│   │
│   └── assets/
│
├── webpack.config.js
├── package.json
├── Dockerfile
└── nginx.conf
```

---

# 53. Backend Structure

Suggested:

```text
backend/
├── public/
│   └── index.php
│
├── src/
│   ├── Controllers/
│   ├── Services/
│   ├── Repositories/
│   ├── Models/
│   ├── Middleware/
│   ├── Auth/
│   ├── Validation/
│   ├── Billing/
│   ├── Store/
│   ├── Subscription/
│   └── BigStore/
│
├── migrations/
├── tests/
├── composer.json
├── Dockerfile
└── .env.example
```

Use a front controller.

Do not create hundreds of standalone PHP endpoint files.

---

# 54. BigStore Structure

Suggested:

```text
bigstore/
├── src/
│   ├── server.js
│   ├── database.js
│   ├── storage.js
│   ├── uploads.js
│   ├── streaming.js
│   ├── shares.js
│   ├── quota.js
│   ├── media.js
│   └── security.js
│
├── migrations/
├── tests/
├── package.json
├── Dockerfile
└── .env.example
```

---

# 55. Migration System

Both databases need numbered migrations.

Example:

```text
001_initial.sql
002_wallets.sql
003_subscriptions.sql
004_stores.sql
005_orders.sql
006_movies.sql
```

Never rely on automatically creating tables from production application startup code.

---

# 56. Seed Data

Development mode should create:

```text
Admin
Partner
Customer
```

and sample:

```text
Store
Products
Public directory
Movie metadata
```

Development passwords should exist only in local seed configuration.

---

# 57. Testing

Create tests for all critical systems.

Backend tests:

```text
authentication
authorization
subscriptions
wallet
partner billing
stores
checkout
admin permissions
```

BigStore tests:

```text
uploads
quota
file access
range requests
checksums
share tokens
path traversal prevention
```

Frontend tests:

```text
API client
routing
critical UI behavior
```

Critical integration tests:

```text
partner credits customer
subscription renewal
store checkout
refund
50 GB quota rejection
share expiration
movie streaming authorization
admin partner payment
```

---

# 58. Financial Integrity Tests

These are mandatory.

Test:

```text
two simultaneous purchases
duplicate checkout request
duplicate partner top-up
refund twice
admin payment twice
insufficient balance
stock race
```

Every mutation endpoint that could accidentally execute twice should support idempotency.

Example:

```text
Idempotency-Key
```

---

# 59. Error Format

All backend errors follow one format:

```json
{
  "success": false,
  "error": {
    "code": "INSUFFICIENT_BALANCE",
    "message": "Your account does not have enough balance."
  }
}
```

Successful response:

```json
{
  "success": true,
  "data": {}
}
```

Never expose stack traces in production.

---

# 60. Observability

Implement:

```text
structured logs
request IDs
audit log
health endpoints
storage metrics
```

Health:

```text
GET /api/health
GET /internal/health
```

Docker health checks should use these endpoints.

---

# 61. Backups

Back up:

```text
backend SQLite
BigStore SQLite
stored files
configuration
```

SQLite backups must use a safe database backup mechanism rather than blindly copying a database during active writes.

Provide:

```text
scripts/backup.sh
scripts/restore.sh
```

---

# 62. README

README must describe:

```text
Requirements
Installation
Docker deployment
Development
Configuration
Metadata API setup
First admin creation
Backups
Updating
Directory structure
Architecture
```

Installation should ideally be:

```bash
cp .env.example .env
docker compose up -d --build
```

---

# 63. Environment Configuration

Root `.env.example` should contain things such as:

```text
APP_ENV
APP_URL

ENTRY_CODE

SESSION_SECRET
INTERNAL_BIGSTORE_SECRET

MOVIE_METADATA_PROVIDER
TMDB_API_KEY

MAX_UPLOAD_SIZE

DEFAULT_STORAGE_QUOTA_BYTES

SQLITE_BACKEND_PATH
SQLITE_BIGSTORE_PATH
```

Never commit actual secrets.

---

# 64. Development Phases

Do NOT attempt to implement the entire platform in one uncontrolled pass.

## Phase 1 — Foundation

Build:

* Repository structure
* Docker Compose
* Webpack
* PHP backend
* BigStore
* SQLite migrations
* Health endpoints
* Reverse proxy
* Environment configuration

Acceptance:

```text
docker compose up --build
```

starts the full stack successfully.

---

## Phase 2 — Authentication

Build:

* Users
* Roles
* Login
* Logout
* Sessions
* Middleware
* Customer/partner/admin authorization
* Initial admin

Acceptance:

All three roles can authenticate and cannot access unauthorized areas.

---

## Phase 3 — Landing Page

Build:

* Corporate startup homepage
* Hidden search-bar code
* Server-side entry-code verification
* Login page

Acceptance:

Normal visitor sees only the public corporate site.

Correct code provides access to login.

---

## Phase 4 — BigStore Core

Build:

* Directories
* Files
* Uploads
* Downloads
* Storage accounting
* Checksums
* BigStore internal API

Acceptance:

Authorized test user can upload/download files.

---

## Phase 5 — Subscriptions

Build:

* Request
* Partner approval
* Reject
* Expiration
* Renewal
* 50 GB quota

Acceptance:

Only active subscribers receive storage access.

---

## Phase 6 — Wallet

Build:

* Wallets
* Ledger
* Partner top-up
* Transaction history
* Atomic transactions

Acceptance:

Money cannot be created or removed without ledger entries.

---

## Phase 7 — Partner Billing

Build:

* Billing entries
* Outstanding balance
* Admin payments
* Statements

Acceptance:

Customer top-up and subscription renewal automatically generate correct partner debt.

---

## Phase 8 — File Sharing

Build:

* Share links
* Expiry
* Passwords
* Download permissions
* Revoke

Acceptance:

Unauthorized users cannot derive BigStore paths or bypass link permissions.

---

## Phase 9 — Movie Mode

Build:

* Movie folders
* Scanner
* Filename parsing
* Metadata provider
* Posters
* Movie UI
* Range streaming

Acceptance:

Dropping a valid movie into a movie folder eventually creates a movie-library item with metadata and playable media.

---

## Phase 10 — Stores

Build:

* Store creation
* Branding
* Store homepage
* Categories
* Products
* Images
* Variants
* Inventory

Acceptance:

Partner can create an independently branded working storefront.

---

## Phase 11 — Cart and Orders

Build:

* Cart
* Checkout
* Internal-credit payment
* Inventory
* Orders
* Invoice snapshots

Acceptance:

Purchase is atomic and cannot double-charge.

---

## Phase 12 — Store Accounts

Build:

* Store employees
* Store roles
* Permissions

Acceptance:

Store employee has only explicitly permitted store access.

---

## Phase 13 — Public Directory

Build:

* Admin directory editor
* Files
* Movies
* Collections
* Public pages

Acceptance:

Admin controls exactly what anonymous users can access.

---

## Phase 14 — Admin System

Finish:

* User management
* Partner management
* Billing
* Stores
* Public directory
* Movie management
* Storage
* Logs
* Settings

---

## Phase 15 — Hardening

Perform:

* Security review
* Permission review
* Financial race-condition tests
* File traversal tests
* XSS tests
* CSRF tests
* Upload abuse tests
* Backup test
* Restore test

---

## Phase 16 — Comprehensive UI/UX Overhaul & Usability Polish (Production-Ready Implementation)

The AI must review, overhaul, and **fully implement** the entire user interface across all views and user flows, transforming the platform from functional screens into an exceptionally intuitive, cohesive, delightful, and genuinely useful production-ready daily application:

* **Production-Ready Implementation Mandate**:
  * This is not a superficial design pass or conceptual mockup. Every feature must be **fully implemented, robust, and working end-to-end** in production condition.
  * Real drag-and-drop file upload with live progress bars, speed metrics, and cancellation support.
  * Real interactive in-browser file preview modals for pictures, streaming video, audio, and code/text documents.
  * Real global non-blocking toast notifications replacing disruptive `alert()` and `confirm()` dialogs.
  * Real 1-click clipboard link copying with instant visual confirmation.
  * Real video player overlays with playback speed controls, theater/fullscreen mode, resume playback from timestamp, and keyboard shortcuts.
  * Real quick-checkout with saved address prefill, balance check with top-up shortcut, and print-ready tax invoices.
  * Real merchant console with fast inline stock editing and order fulfillment workflow.
  * Real responsive mobile/tablet layout drawer navigation.
  * Zero console errors, zero broken links, zero dead buttons, zero placeholder text, zero unhandled errors.

* **Human-Centric Copy & Jargon Eradication**:
  * **Strictly eliminate dry, robotic, internal engineering jargon** across all user-facing screens, marketing landing pages, dropzones, status badges, modals, and tooltips.
  * Specific phrases to eliminate: *"Chunked multi-part streaming with SHA-256 integrity verification"*, *"Finalizing object assembly and SHA-256 verification"*, *"Content-addressed hexadecimal object storage"*, *"Argon2id cryptographic hash verifier"*, internal token prefixes, FastCGI references, etc.
  * **Replace with clear, friendly, human-centric, benefit-driven product copy** that real everyday people actually want to read and understand (e.g., *"Fast & secure uploads, any file size"*, *"Finishing up your upload..."*, *"Generous 50 GB secure cloud storage"*, *"Watch movies in crisp HD with instant streaming"*).

* **Global Design System & Feedback**:
  * Unified, modern aesthetic (dark theme, crisp typography hierarchy, refined spacing, polished card layouts, smooth transitions).
  * Non-blocking global toast notification system (success, warning, error, copy confirmations).
  * Active route indicators, dynamic breadcrumbs, live cart badge counters, and responsive mobile navigation drawer.
  * Informative, actionable empty states with prominent Call-To-Action buttons across every single screen.

* **File Storage & Sharing Usability**:
  * Drag-and-drop upload zone directly into the active folder.
  * Upload progress indicators with transfer speeds and remaining time estimates.
  * Multi-format file preview modal (images, HTML5 video/audio playback, syntax-highlighted code/text).
  * Search, sort (name, size, modification date, type), and grid/list view toggles.
  * 1-click share modal with instantaneous link copying, expiration selector, and password toggle.

* **Media & Movie Streaming Experience**:
  * Cinema-grade catalog with high-resolution poster grid, backdrop heroes, genre filters, and instant search.
  * Rich movie detail modal (synopsis, director, runtime, cast, ratings, format badges).
  * Feature-complete video player overlay with playback speed controls, theater/fullscreen mode, resume playback, and volume memory.

* **Commerce, Cart & Invoicing Usability**:
  * Visually stunning store directory and customizable merchant storefronts.
  * Product modal with image gallery, variant pickers, quantity stepper, and live subtotal calculator.
  * Floating/drawer shopping cart with real-time subscriber fee exemption banner (0.00 € vs 1.00 € fee).
  * 1-click checkout with saved address prefill, balance sufficiency indicator, and direct top-up shortcut.
  * Customer order history with live fulfillment timeline and official printable tax invoices.

* **Merchant & Admin Operational Power**:
  * High-productivity Merchant Console: sales metrics, low stock indicators, bulk inventory adjustments, and streamlined order fulfillment with tracking notes.
  * Unified Admin Panel: complete user management, store oversight, billing audits, storage health monitoring, and system audit logs.

* **Responsive & Accessibility Verification**:
  * Verified across mobile, tablet, laptop, and desktop viewports with zero layout shifts or overflows.
  * Full keyboard accessibility, visible focus rings, and zero console warnings/errors.

---

# 65. Antigravity Agent Organization

Use specialized agents rather than one agent owning everything.

Suggested responsibilities:

```text
Architect Agent
Frontend Agent
Backend Agent
BigStore Agent
Store/Commerce Agent
QA Agent
Security Agent
DevOps Agent
```

## Architect Agent

Owns:

* architecture
* schemas
* API contracts
* cross-service design

Must review architecture-impacting changes.

## Frontend Agent

Owns:

```text
webapp/
```

Must not modify backend behavior without API-contract coordination.

## Backend Agent

Owns:

```text
backend/
```

## BigStore Agent

Owns:

```text
bigstore/
```

## Commerce Agent

Owns logical design/testing for:

```text
wallet
billing
stores
checkout
orders
```

## QA Agent

Owns:

```text
integration tests
regression tests
acceptance verification
```

## Security Agent

Reviews:

```text
authorization
uploads
streaming
financial operations
sessions
secrets
```

## DevOps Agent

Owns:

```text
Docker
Compose
health checks
backup
deployment
```

---

# 66. AGENTS.md Rules

Create an `AGENTS.md` containing these mandatory rules:

1. Read `PROJECT_PLAN.md` before making architectural changes.
2. Keep `webapp`, `backend`, and `bigstore` separated.
3. Browser never directly accesses BigStore.
4. BigStore is internal-only.
5. Frontend cannot enforce authorization.
6. Never use floating-point money.
7. Every money change requires a ledger entry.
8. Every partner debt change requires a billing entry or payment entry.
9. Never store plaintext passwords.
10. Never expose filesystem paths.
11. Never trust frontend price/balance/permission values.
12. All uploads must be securely validated.
13. All sensitive actions require authorization checks.
14. Add migrations for database changes.
15. Add tests for important behavior.
16. Do not silently change API contracts.
17. Run relevant tests before completing a task.
18. Verify the affected UI in the browser when UI changes are made.
19. Do not consider a task complete merely because it compiles.
20. Record unfinished items clearly.

---

# 67. Definition of Done

A feature is complete only if:

```text
implementation exists
+
database migrations exist
+
authorization exists
+
validation exists
+
error handling exists
+
tests exist
+
relevant documentation exists
+
Docker build still works
+
browser/API behavior has been verified
```

---

# 68. First Antigravity Task

Start with ONLY Phase 1.

Instructions to the agent:

Analyze this specification and create the initial project architecture for `webapp`, `backend`, and `bigstore`.

Implement:

1. Directory structure
2. Dockerfiles
3. Docker Compose
4. Webpack frontend
5. Minimal PHP backend
6. Minimal BigStore service
7. Separate SQLite databases
8. Internal Docker network
9. Health endpoints
10. Reverse-proxy routing
11. Environment configuration
12. Initial migration system
13. Basic automated health tests
14. README

Do not start building stores, accounts, movies, billing, or file sharing during Phase 1.

After implementation:

* Build every container.
* Run Docker Compose.
* Verify frontend through a browser.
* Verify backend health endpoint.
* Verify BigStore internal health endpoint from backend/container network.
* Run tests.
* Fix failures.
* Produce an implementation report listing files created, architecture decisions, tests run, and remaining work.

Only then proceed to Phase 2.

---

# 69. Ultimate Acceptance Scenario

The finished platform should support this end-to-end scenario:

1. Visitor opens the domain.
2. Visitor sees a polished startup/coming-soon company page.
3. Visitor enters the secret entry code into the search field.
4. Login screen becomes accessible.
5. Customer logs in.
6. Customer requests a subscription.
7. Partner approves it.
8. Customer receives 50 GB storage.
9. Customer uploads files and shares one with another person.
10. Customer opens Movie Mode and streams an authorized movie.
11. Partner adds €50 credit to the customer.
12. Customer wallet increases €50.
13. Partner outstanding bill increases €50.
14. Partner renews customer's subscription.
15. Partner bill increases by renewal price.
16. Customer visits a partner's store despite the subscription not being required for shopping.
17. Customer buys a product using account balance.
18. Store receives the order.
19. Correct invoice behavior follows the store's invoice-retention setting.
20. Admin sees the partner's outstanding debt.
21. Admin records a partner payment.
22. Outstanding debt decreases without destroying historical billing entries.
23. Partner creates a limited staff account for their store.
24. Admin publishes a movie/file collection to the public directory.
25. Anonymous visitor can browse exactly those public resources the admin selected.

All of these workflows must function without violating role isolation, storage permissions, financial integrity, or service boundaries.
