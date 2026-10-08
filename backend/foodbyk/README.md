# Food by K

Food by K is a mobile-first pre-ordering backend for a fast-food vendor. Customers browse the menu, authenticate before adding items to a cart, choose collection or delivery, submit a tokenized-card order, and receive status updates. Staff review orders before a payment token is charged; administrators manage products, categories, users, and promotions.

The authoritative business rules are in [DOMAIN.md](DOMAIN.md). Contributor and architecture rules are in [AGENTS.md](AGENTS.md).

## Architecture

The backend is plain PHP with MySQL/MariaDB and no application framework or ORM.

```text
HTTP controller -> service -> Active Record model -> PDO/MySQL
```

- Controllers parse HTTP input, invoke one service method, and produce a response.
- Services own validation, business workflows, transactions, and multi-model coordination. They return `['success' => bool, 'data' => mixed, 'error' => ?string]`.
- Models represent tables and perform single-record persistence. They do not start transactions or orchestrate services.
- `Database` exposes the shared PDO connection using credentials from environment variables.

The source layout is:

```text
backend/
  public/                 HTTP entry point and built-in-server router
  foodbyk/
    config/               environment-backed constants
    controllers/          HTTP boundary
    core/                 router and JSON response helpers
    database/             PDO connection
    middleware/           authentication, role, CSRF, rate-limit boundaries
    models/               Active Record models
    services/             application workflows
  tests/model_checks.php  dependency-free domain/service checks
```

## Core Flows

### Authentication

`AuthService` provides registration, login, logout, and password changes.

- Email addresses are trimmed and lowercased before use.
- Customer registration resolves the `customer` role and writes its `role_id`; roles are not client-controlled.
- Registration is transactional and relies on a database unique constraint on `users.email` to protect concurrent requests.
- Successful login and registration regenerate the session ID and store only the user ID plus authentication time.
- Failed login responses are intentionally generic. The unknown-user path performs a password verification against a fixed dummy hash to reduce basic email-enumeration timing differences.
- Passwords must be 12-128 characters, contain upper-case, lower-case, number, and symbol characters, contain no whitespace, and must not contain the email local-part or full name.
- New hashes use Argon2id when available, with bcrypt fallback. A successful login upgrades an outdated hash automatically.
- Password hashes and payment tokens must never be returned in API responses or logs.

### Catalogue Products

`ProductService` is the catalogue boundary.

- Public browsing returns only active and available products.
- Search and category listing apply the same visibility rule.
- Create and update operations validate category IDs, names, descriptions, prices, availability values, lifecycle status, and image URLs.
- Removing a product is a soft removal: it changes the status to `removed` and disables availability. This preserves historical order-item relationships.
- Role enforcement for staff/admin routes belongs in middleware/controllers; service methods still validate all untrusted values.

### Promotions

`Promotion` supports these discount types:

| Type | Behaviour |
| --- | --- |
| `percentage` | Percentage off the item subtotal, capped at 100%. |
| `fixed_amount` | Fixed amount off the item subtotal, capped at the subtotal. |
| `buy_one_get_one` | Every pair of matching product items makes the lower-priced unit free. |
| `free_delivery` | Discounts the delivery fee. |

Promotion calculations use immutable order-item snapshots (`product_id`, `quantity`, `unit_price`) rather than live product prices. The computed discount must be stored on `orders.locked_discount` when an order is submitted and revalidated only if staff adjust the order.

Admin-only `PUT /admin/staff/{id}`, `PUT /admin/products/{id}`, and `PUT /admin/promotions/{id}` routes edit records through their owning services. `DELETE /admin/staff/{id}` deactivates staff accounts, `DELETE /admin/products/{id}` marks products removed, and `DELETE /admin/promotions/{id}` deactivates promotions; these operations preserve historical references rather than deleting database rows. Staff updates accept `full_name`, `email`, `phone`, and optionally `is_active`. Promotion updates accept `code`, `discount_type`, `discount_value`, `start_date`, `end_date`, and `is_active`; send `null` for an optional date to clear it.

### Orders and Payments

Order status changes use `Order::ALLOWED_TRANSITIONS` as the single state-machine source of truth. Every status mutation must occur inside a database transaction after `Order::lockById()` has locked the row, and must insert an `OrderStatusHistory` entry in the same transaction.

Payment is a two-stage PayFast tokenization workflow:

1. Submission obtains a PayFast token; no money is charged.
2. After staff acceptance, `PaymentService` charges the token.
3. The asynchronous PayFast notification determines final payment success/failure.

`Payment` does not change orders directly. The service coordinates payment, locked order status, and history atomically. This avoids concurrent staff-review and duplicate-webhook races.

## Requirements

- PHP 8.3 is used in CI. PHP 8.0+ is required by the typed-property and union-type syntax, though PHP 8.3 is recommended.
- MySQL 8.0.16+ or MariaDB 10.2+ for `CHECK` constraints.
- PDO MySQL extension.

Environment variables consumed by `backend/foodbyk/config/config.php`:

```text
APP_ENV=development
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=foodbyk
DB_USER=root
DB_PASS=
DB_SSL_CA=
PAYMENT_TOKEN_ENCRYPTION_KEY= # base64-encoded 32-byte key; required for token storage
RESEND_API_KEY=...
TWILIO_SID=...
TWILIO_AUTH_TOKEN=...
TWILIO_FROM_NUMBER=+27...
TWILIO_WHATSAPP_FROM=+15106606876
TWILIO_WHATSAPP_SANDBOX=false
TWILIO_WHATSAPP_SANDBOX_FROM=whatsapp:+14155238886
TWILIO_WHATSAPP_SANDBOX_RECIPIENTS=+27699307496
TWILIO_TEMPLATE_STAFF_NEW_ORDER=HX...
TWILIO_TEMPLATE_CUSTOMER_CONFIRMED=HX...
TWILIO_TEMPLATE_CUSTOMER_DECLINED=HX...
TWILIO_TEMPLATE_CUSTOMER_PAID=HX...
TWILIO_TEMPLATE_CUSTOMER_PAYMENT_FAILED=HX...
```

Twilio WhatsApp normally sends use the configured Content Template Builder SIDs. Set `TWILIO_WHATSAPP_SANDBOX=true` for Sandbox testing; this sends free-form notification text from the Sandbox sender only to the comma-separated allowlist in `TWILIO_WHATSAPP_SANDBOX_RECIPIENTS`. Each recipient must have joined the Sandbox, and free-form messages are limited to the 24-hour customer service window after their last inbound WhatsApp message. Set the switch back to `false` after upgrading to use the live sender and approved templates again. SMS does not use template SIDs. Configure a verified Resend sender domain before sending email. `DOMAIN.md` additionally defines PayFast and geocoding configuration.

### Database setup and production

For a new database, run `database/migration.sql`. Existing databases should be backed up and then upgraded once with `database/harden_existing_schema.sql`; the upgrade fails if current rows violate the new checks, so inspect and correct offending rows first. Set `APP_ENV=production`, provide explicit database credentials for a dedicated non-root MySQL user, and configure `DB_SSL_CA` when the database requires TLS.

Payment tokens are encrypted by the `Payment` model using AES-256-GCM. Generate `PAYMENT_TOKEN_ENCRYPTION_KEY` with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`; store it in the deployment secret manager and keep a protected backup. Existing plaintext tokens are encrypted when their payment rows are next saved. `Payment` JSON omits the token.

## Local Checks

Run the same checks used by GitHub Actions from the repository root:

```powershell
Get-ChildItem backend -Recurse -Filter *.php | ForEach-Object {
  php -l $_.FullName
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

php backend/tests/model_checks.php
```

The test runner covers order totals/transitions, coordinate validation, promotion types and expiry, password policy, and product-input validation. It intentionally does not need a database.

## CI

GitHub Actions runs on pushes and pull requests to `main`:

1. PHP syntax lint for every backend PHP file.
2. `backend/tests/model_checks.php`.

See [.github/workflows/ci.yml](.github/workflows/ci.yml).

## Current Status and Next Work

The project is still a backend scaffold. Several controllers, middleware classes, routing/bootstrap code, and service workflows remain placeholders. The documented database migration is also not currently committed, so database-backed registration, product mutation, checkout, and payment tests cannot run until the schema is added.

Priority follow-up work:

1. Commit the migration and seed the `roles` and `business_settings` records.
2. Implement bootstrap/autoloading, router, response helpers, and controllers.
3. Implement cart, delivery, checkout, order, and PayFast webhook services with real MySQL integration tests.
4. Configure secure production session cookies (`Secure`, `HttpOnly`, `SameSite`) and disable PHP error display in production.
