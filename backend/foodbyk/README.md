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
DB_HOST=localhost
DB_NAME=foodbyk
DB_USER=...
DB_PASS=...
```

`DOMAIN.md` additionally defines production configuration needed for PayFast, geocoding, email, and optional Twilio notifications.

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
