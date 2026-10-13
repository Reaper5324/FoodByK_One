# Food by K test guide

This guide explains the checks in this repository, what each one proves, and
what still needs a human or an external service. CI runs repeatable automated
checks on pushes and pull requests to `main`; it does not claim that a passing
build proves the whole business is ready to launch.

## Test coverage by category

| Requested category | Automated/manual coverage | What is checked |
| --- | --- | --- |
| Security | `security_checks.php` and `api_integration_checks.php` | PayFast token encryption, authenticated API access, role boundaries, CSRF enforcement, session cookie flags, safe account responses, and CORS allow/block behavior. |
| Integration | `api_integration_checks.php` with the real API server and MySQL | Health and catalogue endpoints, customer registration/session, profile update, middleware, API response codes, and database-backed request handling. |
| User acceptance | [`USER_ACCEPTANCE.md`](USER_ACCEPTANCE.md) | Human checks of customer, staff, admin, payment-provider, notification, accessibility, and device scenarios in staging. |
| Data validation | `model_checks.php`, `database_checks.php`, and API integration checks | Password/account, product, promotion, address-coordinate, and request validation at service, API, and SQL-constraint boundaries. |
| User interface | `frontend/tests/ui/customer-pages.spec.js` | Chromium rendering and asynchronous product/cart/order behavior with controlled API responses. |
| Database | `database_checks.php` | Migration seed data, uniqueness, foreign keys, check constraints, and transaction rollback against MySQL 8. |

## Automated checks

### PHP domain and input checks

Run from the repository root:

```sh
php backend/tests/model_checks.php
php backend/tests/security_checks.php
```

`model_checks.php` does not connect to MySQL. It exercises order totals and
state transitions, address-coordinate bounds, promotion calculations and
caps, password and registration rules, and product input validation.

`security_checks.php` verifies that a payment token is encrypted before
persistence, encryption uses a fresh nonce, tampering is rejected, and
serialized payment JSON never exposes the token.

### Database and API integration checks

These scripts write temporary rows. Use a throwaway MySQL 8 database created
from `backend/foodbyk/database/migration.sql`; both scripts refuse to run
unless its name is exactly `foodbyk_test`. Never use a production or personal
development database.

Set the connection variables for the test scripts and the API:

```text
TEST_DB_HOST=127.0.0.1
TEST_DB_PORT=3306
TEST_DB_NAME=foodbyk_test
TEST_DB_USER=root
TEST_DB_PASS=<test database password>

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=foodbyk_test
DB_USER=root
DB_PASS=<test database password>
APP_ENV=testing
PAYMENT_TOKEN_ENCRYPTION_KEY=<base64-encoded 32-byte test key>
FRONTEND_URL=https://foodbyks.netlify.app
```

After applying the migration, run the database checks:

```sh
php backend/tests/database_checks.php
```

They insert uniquely named test rows and remove them at the end. They check
the seeded customer role and business settings row, unique user email,
product foreign key and nonnegative price, address coordinate constraints,
and rollback behavior.

Start the API in another terminal using the same test database settings:

```sh
cd backend/public
php -S 127.0.0.1:8080 router.php
```

Then, from the repository root:

```text
API_BASE_URL=http://127.0.0.1:8080
```

```sh
php backend/tests/api_integration_checks.php
```

The integration test makes real HTTP requests. It checks health and public
catalogue responses, verifies anonymous and customer access boundaries,
registers a uniquely named test customer, checks session and CSRF behavior,
updates the test profile, and verifies the configured frontend origin is
allowed while an unrelated origin is denied. It deletes the temporary
customer in a `finally` cleanup block. If the PHP process is forcibly killed,
inspect and remove any `api-check-...@example.test` row from the **test**
database before rerunning.

### Customer browser checks

The browser suite serves the static frontend locally and intercepts API calls;
it does not contact the deployed API or change real customer data.

```sh
cd frontend
npm install
npx playwright install chromium
npm run test:ui
```

It checks that menu product placeholders are replaced by loaded cards, the
cart removes its skeleton rows before displaying real items (including a
two-item cart), and delivery progress says “Out for delivery” and “Delivered”.
These are browser behavior checks, not full production-backend tests.

## CI workflow

`.github/workflows/ci.yml` has three independent jobs:

1. **Backend unit, validation, and security:** PHP syntax linting, domain and
   input checks, and payment-token security checks.
2. **Database, API integration, and authorization:** starts a clean MySQL 8
   service, applies the migration, runs database checks, starts the PHP API,
   then checks sessions, CSRF, role access, and CORS over HTTP.
3. **Customer UI:** installs Playwright and Chromium, then runs the customer
   browser suite. Failure traces are uploaded as a workflow artifact.

The workflow uses synthetic credentials and a disposable database. It never
calls PayFast, Resend, or Twilio.

## Manual user acceptance and limits

Use [`USER_ACCEPTANCE.md`](USER_ACCEPTANCE.md) against staging for the release
flows that cannot be credibly proven by the automated suite: real PayFast
sandbox redirects and callbacks, staff order review, actual email/WhatsApp
delivery, assistive-technology review, and physical phone/tablet behavior.
Record failures and accepted exceptions with the build/commit and issue link.

CI cannot prove provider availability, business acceptance of the workflow,
or that the site behaves correctly on every device. Passing CI is necessary,
but staging UAT remains part of release sign-off.
