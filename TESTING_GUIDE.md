# Food by K — Comprehensive Testing Guide

**Purpose:** define how Food by K changes are tested, what evidence is required, and what a test pass does and does not mean. This guide covers the customer website, PHP API, relational database, RAG assistant, payment and messaging integrations, staff/admin workflows, and deployment behavior.

**Audience:** developers, reviewers, QA, operations, and business acceptance testers.

**Scope note:** tests must use local or staging systems and synthetic accounts. Never point a test at production data or send an unapproved test charge/message to a real customer.

## 1. Assessment of the existing criteria

The repository already has a useful layered baseline:

| Existing asset | Current strength | Current limitation |
|---|---|---|
| `backend/tests/model_checks.php` | Exercises selected business rules without a database, including order totals, state transitions, promotion calculations, product bounds, and optional-extra validation. | Does not cover every rule or transactional boundary. New rules can be implemented without a corresponding case unless explicitly added. |
| `backend/tests/security_checks.php` | Checks payment-token encryption, fresh nonces, authenticated decryption, tamper rejection, and safe serialization. | Focuses on payment-token handling; broader abuse cases depend on API integration and UAT. |
| `backend/tests/database_checks.php` | Verifies selected migration seeds, uniqueness, foreign keys, constraints, and rollback using MySQL. | Does not prove all service transactions or concurrent behavior. |
| `backend/tests/api_integration_checks.php` | Exercises a real local API and database for health/catalogue, customer session and profile, middleware, CSRF, and CORS. | Has limited workflow coverage compared with the route surface; it is not a full order/payment/provider integration test. |
| `frontend/tests/ui/customer-pages.spec.js` | Uses a browser and controlled API responses to check menu loading, cart skeleton cleanup, delivery progress, promotion labels/images, option pricing/submission, and mobile menu layout. | Still lacks automated checkout, admin interactions, assistant UI, and a full accessibility audit. |
| `backend/tests/load_test.php` | Measures a bounded baseline for public GET endpoints and reports latency percentiles, failures, throughput, and per-endpoint thresholds. | Does not simulate a full customer journey, write contention, RAG/Groq, or production capacity. Its thresholds are CI baselines, not production SLOs. |
| `RagService/tests/test_retrieval.py` and `evaluation_cases.json` | Cover selected query expansion, retrieval fusion, policy boundaries, live-menu routing, catalogue failure, and unsafe/transactional intents. | CI runs deterministic cases, but examples are not a scored answer-quality benchmark and do not exercise a deployed PHP→RAG→catalogue path. |
| `backend/tests/USER_ACCEPTANCE.md` | Establishes a human staging checklist for ordering, account/accessibility, staff, payment, and notifications. | Needs more explicit cases for promotions, product customizations, notification details, assistant behavior, cache freshness, failure recovery, and evidence/sign-off. |

The test suite is strongest at input/domain checks and the basic happy-path plumbing. It is weaker at cross-layer business invariants, external-provider failure handling, browser interactions for recent features, and repeatable RAG answer-quality evaluation. The criteria below close those specification gaps. They do not claim all listed cases are already automated.

## 2. Principles that govern the test design

1. **Test business outcomes and invariants.** Assert what must remain true—such as server-calculated totals, customer ownership, legal state transitions, and one effective promotion—not private implementation details.
2. **Test at the narrowest useful boundary.** Use pure/domain tests for calculations, service/database integration tests for transactions, HTTP tests for middleware and API contracts, browser tests for visible behavior, and staging UAT for real providers and business acceptance.
3. **Keep one authoritative decision-maker.** Browser prices are previews; backend services and persisted snapshots determine chargeable order totals. The database enforces structural constraints. RAG explains public facts but never performs account or order actions.
4. **Test both sides of every trust boundary.** Include permitted and denied roles, valid and invalid CSRF, owned and foreign records, allowed and hostile origins, trusted and hostile FAQ content, and successful and failing provider responses.
5. **Test state transitions explicitly.** Cover allowed transitions, rejected transitions, idempotent retries, terminal states, and concurrent attempts. A workflow passing once is not proof that it is safe to repeat.
6. **Make tests isolated and repeatable.** Use unique synthetic records, resettable fixtures, fixed clocks where time matters, deterministic provider fakes, and throwaway databases. Tests must not depend on run order or public production data.
7. **Fail closed on financial and privacy decisions.** Unknown prices, malformed payment callbacks, stale promotions, invalid ownership, or missing required order data must not create a chargeable or visible order.
8. **Exercise failures and recovery.** Network timeout, provider rejection, database rollback, partial API failure, empty data, expired data, and restart behavior are product conditions, not exceptional test-only details.
9. **Check usability as well as correctness.** Error messages, keyboard interaction, responsive layouts, loading/empty/error states, and assistive announcements are part of acceptance.
10. **Match assurance to risk.** Payment, authorization, privacy, and order integrity require negative, retry, and audit checks in addition to happy-path checks. A test result is evidence for the specific environment and data that were exercised.

## 3. Architecture and test layers

Food by K has independently deployed components. Keep the test boundary clear:

```text
Browser (customer / staff / admin)
  ├─ Static HTML/CSS/JavaScript and service worker
  └─ HTTPS JSON API calls
       ↓
PHP router → middleware → controller → service → model/PDO → MySQL
       ├─ PayFast (tokenization and asynchronous charge callbacks)
       ├─ Resend / SMTP, Twilio WhatsApp / SMS, Web Push
       ├─ Geocoding and S3-compatible image storage
       └─ PHP RAG proxy → FastAPI RAG → public catalogue API + FAQ index + LLM
```

Use the following layers in combination:

| Layer | Main purpose | Typical repository location | Run frequency |
|---|---|---|---|
| Static checks | Syntax, formatting, dependency/config mistakes | CI lint and review checks | Every change |
| Domain/unit | Calculations, parsers, validators, transition rules | `backend/tests/model_checks.php`, RAG unittest | Every change to the rule |
| Service/component | Service behavior with provider/database fakes where available | Backend and RAG test suites | Every service change |
| Database integration | SQL constraints, transactions, snapshots, locking | `database_checks.php`, additional workflow integration checks | Every persistence or transaction change |
| HTTP/contract integration | Request/response envelope, auth middleware, role/CSRF, CORS, API semantics | `api_integration_checks.php` | Every route or middleware change |
| Browser/UI | Observable rendering, user actions, accessibility hooks, failure states | Playwright under `frontend/tests/ui/` | Every user-facing flow change |
| End-to-end/UAT | Multiple deployed services and actual business decisions | `USER_ACCEPTANCE.md` against staging | Release candidate |
| Non-functional | Latency, load, recovery, observability, accessibility | Load script and staging runbooks | Risk-based and before major releases |

Do not replace a lower-level test with a slow end-to-end test when the lower-level test can isolate the rule. Do not treat mocks as evidence that an external provider works; use provider sandboxes for that claim.

## 4. Test environments and data safety

### 4.1 Environment rules

- **Unit/domain:** no network, no shared database, no real secret required. Use synthetic values.
- **Integration:** disposable MySQL 8 database created from `backend/foodbyk/database/migration.sql`; use the scripts' `foodbyk_test` guard. Do not point `TEST_DB_*` or `DB_*` at production or a personal database.
- **Browser:** local static frontend and mocked API responses by default. For browser-to-real-API checks, use a dedicated staging environment and synthetic accounts.
- **Staging/UAT:** isolated database, PayFast sandbox, allowlisted messaging recipients, safe test email recipients, test object-storage bucket/prefix, and staging RAG index. Mark test accounts/orders clearly.
- **Production:** monitoring and read-only smoke checks only unless an explicitly approved operational procedure says otherwise. No synthetic order, payment, email, WhatsApp, or data mutation from the test suite.

### 4.2 Test data conventions

- Use unique addresses such as `qa-<run-id>@example.test`; never use a customer's real email or phone.
- Create known catalogue fixtures with stable IDs or names, prices, availability, categories, and extras. Do not assume the live menu remains unchanged.
- Keep a compact fixture with: one available basic item, one item with paid and free options, one temporarily unavailable item, each supported promotion type, one collection address/time, and one eligible delivery address/time.
- Store fixture setup and cleanup in the test harness. Cleanup must run in `finally`/teardown paths; provide a documented manual cleanup query for interrupted runs.
- Avoid using order dates or promotion dates dependent on a developer's local timezone. Fix the test clock/timezone or compute dates explicitly in UTC and the configured business timezone.
- Never print credentials, session cookies, payment tokens, private addresses, or full request bodies containing personal information into test logs/artifacts.

### 4.3 Database safety

1. Verify the target database name is exactly the disposable test database before running a script that writes rows.
2. Apply the current clean migration for a fresh database. Test upgrade scripts against a copy of a prior schema separately; do not run the clean migration on a populated database.
3. Assert both successful persistence and rollback after induced failure for multi-record workflows.
4. Check snapshot behavior: later product name/price changes must not silently rewrite historic order lines or amounts.
5. Do not share mutable fixtures across parallel tests unless they are isolated by transaction or unique run ID.

## 5. Functional test criteria by domain

For every feature, cover: valid input, boundary input, invalid input, unauthorized actor, persistence/reload, and failure/retry when applicable.

### 5.1 Identity, account, and authorization

- Register with valid and invalid email, phone, password, required policy acknowledgment, duplicate email, and overlong values. Invalid attempts create no partial account or verification record.
- Verify the email token once, reuse it, expire it, and request a resend. Only a valid unexpired token changes verification state.
- Login/logout, session expiry, password reset, password change, account deactivation/deletion, and repeated requests return safe, predictable outcomes.
- Anonymous, customer, staff, and admin roles each exercise permitted and forbidden routes. Changing a client-supplied role/user ID never changes server authorization.
- For each state-changing route, try missing/invalid CSRF token and cross-site origin. For read routes, verify only the intended authentication and ownership checks apply.
- Customer A cannot view/update Customer B's profile, address, cart, ticket, order, payment or loyalty history by substituting an identifier.
- Session cookies use secure attributes in HTTPS configuration; API responses and logs never serialize password hashes, reset tokens, payment tokens, or secrets.
- Rate limits cover login, password reset/verification, assistant, and other configured abuse-sensitive routes; limits recover after their configured window.

### 5.2 Catalogue, images, and product customizations

- Public category/product/search results include only public, active, available items according to the API contract. Invalid category/product IDs and empty searches have safe responses.
- Admin creates, edits, disables, restores where allowed, and removes a product; confirm validation for name, description, category, price, options, image URL/file, content type, file size, and storage failure.
- Product images load at menu, menu-item, promotion, and staff order views. Test missing URL, expired signed URL, 404, slow response, and cache hit/miss. A failed image must not break item details or controls.
- Create a product with no extras; one free extra (`0.00`); a paid extra; multiple extras; edit/remove extras; and submit incomplete name/price pairs, negative price, malformed price, or over-limit options.
- The admin extras editor renders rows, preserves values during edit, adds/removes rows, leaves an empty row harmless, and clearly validates a partially completed pair. Keyboard-only use must reach and operate add/remove controls.
- Customer can select valid extras, sees the correct incremental price, and can remove or change selections. Tampered, removed, foreign-product, duplicate, or unavailable option IDs are rejected by the server.
- Cart and order line totals include option prices exactly once. Order snapshots preserve selected option names and prices when catalogue options later change.

### 5.3 Cart and checkout

- Add, update, remove, and clear cart items. Verify quantity boundaries (minimum, maximum per item, aggregate/cart cap), integer-only rules, and stale/deleted/unavailable products.
- Exercise two independent items and multiple customizations of the same product. The displayed subtotal must match the sum of server-priced line totals.
- Change quantities/options and confirm the review total updates from a fresh server preview. Simulate delayed, failed, duplicated, and out-of-order preview responses; the UI must not present stale totals as current.
- Submit an empty cart, invalid address, unavailable/full time slot, malformed option, stale price, invalid promotion, and expired session. No unintended order or charge is created.
- Submit collection and delivery separately. Confirm collection has no delivery address/fee and delivery requires an eligible address, distance, delivery fee, and capacity slot.
- Force a database failure after an order or order-item write and verify the transaction rolls back all partial records and reserved slot changes.
- Retry a submission after a network timeout. The customer must not create duplicate orders or duplicate charges; if the current design cannot guarantee idempotency, record and resolve this as a release-blocking defect before relying on retries.
- Compare preview, submitted order, staff review, and final charge values. All must derive from backend-calculated prices and discounts, never browser-provided totals.

### 5.4 Promotions and loyalty

For every promotion type (`percentage`, `fixed_amount`, `buy_one_get_one`, `free_delivery`), test all of the following dimensions:

- whole-order versus targeted products;
- eligible versus ineligible basket, including a basket containing both;
- active, inactive, not-yet-started, expires today, and expired dates;
- collection versus delivery where relevant;
- empty cart, quantity one, quantity two or more, and multiple lines for the same product;
- discount cap, rounding to currency precision, and promotion/loyalty combination;
- server response, menu label, customer promo page, checkout breakdown, persisted order snapshot, and staff order view.

Expected domain behaviors to assert against the implementation and approved business rules:

- A percentage discount applies only to its eligible subtotal and cannot exceed the eligible amount.
- A fixed amount is capped at the eligible amount.
- BOGO applies to qualifying pairs of the same eligible product and discounts the lower-priced unit in each pair; it must not combine different products into a free pair.
- Free delivery reduces the delivery charge only when the order is delivery and the configured target condition is met. Collection must not receive a delivery discount.
- Only the allowed number of codes can be selected per order. Applying one code must not silently apply other active codes.
- Every eligible menu item displays its active offer; ineligible items do not imply they receive it. The displayed label must not promise an automatic discount if checkout requires a code.
- Promotion images on the customer page correspond to the correct target products; image failures degrade to a readable placeholder and preserve the offer text.
- Loyalty redemption cannot exceed the customer's balance or the remaining payable total. Failed/declined/cancelled flows restore points according to the business rule; paid orders earn points once, including duplicate callback/retry conditions.

### 5.5 Order state, staff review, and fulfilment

- Exercise every allowed transition in `Order::ALLOWED_TRANSITIONS` and representative forbidden transitions, especially direct submission-to-paid, post-payment cancellation, and transitions from terminal states.
- Staff confirm, adjust, decline with a reason, advance through preparation/ready/completed, and request/complete refunds as allowed. Check audit history and customer-visible status after each action.
- Simultaneous staff updates to one order must not produce conflicting transitions, duplicate charges, or missing history entries. Repeat the same request and validate idempotent behavior or a clear conflict response.
- Adjusted items/options/time must update the authoritative total before charge. A rejected adjustment leaves the prior order intact.
- Delivery and collection progress use distinct labels and completion behavior. A customer's page must not infer progress that the API has not recorded.
- A customer may cancel only in supported states. Staff cannot charge declined/cancelled orders. A failed charge has a clear recovery path and no false paid state.

### 5.6 Payments and refunds

Use PayFast sandbox only:

- Token setup success, decline, timeout, malformed redirect, cancel return, duplicate token callback, and invalid signature.
- Verify no charge is made at order submission; staff acceptance/adjustment is the transition that requests the charge.
- Charge callback success, failure, duplicate success/failure, wrong order/reference/amount/currency, bad signature, and callback arriving after cancellation/decline.
- Assert callback handling is authenticated, idempotent, and transactional. A callback retry cannot double-award loyalty, double-charge, or duplicate order history.
- Verify gateway tokens remain encrypted at rest and absent from JSON, logs, URLs, error responses, browser storage, and email/WhatsApp messages.
- Refund amount bounds, status polling, provider timeout/rejection, duplicate refund request, and audit trail. Do not test live payment or refunds without an approved change window and process.

### 5.7 Notifications and support

- New submitted order notifies staff/admin recipients; confirmation, decline, paid, and failed-payment events notify the intended customer. Each message identifies the order and includes correct item/price/fulfilment/discount/total details for the configured channel.
- Support reply notification is sent to the ticket owner only. A different customer's ticket must never receive the content.
- Test email provider rejection/timeout, Twilio rejection, missing/unapproved WhatsApp template, disallowed Sandbox recipient, push subscription expiry, and browser permission denied. Order state changes must still succeed when notification delivery fails.
- A duplicate payment callback or staff retry must not cause unintended duplicate notifications unless the business explicitly permits them.
- Verify staff incoming-order browser notification has title/body, sound behavior where browser/platform permits, and a route to review/acknowledge the order. Verify customer status and ticket reply push notifications show no sensitive details on a locked screen.
- Use allowlisted test numbers and trial email recipients only. Distinguish “provider accepted request” from “message reached handset/inbox”; record both evidence levels.
- Production WhatsApp content is determined by approved Twilio templates. Verify template SID, placeholders, language, opt-in/recipient policy, and rendered output in the provider sandbox before relying on rich message variables.

### 5.8 RAG FAQ assistant

Test the RAG system at three boundaries: retrieval/unit, direct FastAPI service, and customer browser through the PHP proxy.

**Question set and correctness**

- Maintain versioned benchmark questions for business identity/capabilities, hours/location/contact, menu/listing and live prices, ordering, cart limits, checkout, payment timing, delivery/collection, slots, promotions, loyalty, support, and privacy.
- For each fact, store its trusted source, expected intent, must-include facts, must-not-claim facts, and whether live catalogue data is required. Add casual spelling, dialect, paraphrase, misspelling, follow-up, and multi-part variants.
- Score answers independently for factual correctness, grounding, completeness, intent routing, safety/privacy, and clarity. Record failures by question and source; do not use a single vague “sounds good” score.
- Use live PHP catalogue fixtures for names/prices/availability. Never approve a hard-coded or embedded RAG answer for facts that can change in the catalogue.
- Ask the same menu question before and after catalogue update/cache expiry; updated price/availability must be reflected according to the documented cache window.

**Routing and safe behavior**

- General policy question retrieves FAQ context and answers using it; unsupported facts are clearly unknown rather than invented.
- Full-menu and product-recommendation questions select the live catalogue path; budget and cheapest questions respect numeric boundaries and current prices.
- Account, order status, payment status, refund, address-specific delivery, and other transactional questions hand off to the authenticated product flow without inventing an outcome.
- Personal data, secrets, prompt-injection strings, malicious instructions inside retrieved documents, and requests for another customer's data do not reach the LLM as actionable instructions or appear in answers/logs.
- Empty/poor retrieval returns a safe fallback. Third-party or external web results are not presented as official policy or current catalogue data.

**Ingestion and operation**

- Ingest markdown/text/PDF sources with headings and paragraph boundaries retained; malformed, empty, disallowed, or partially fetched sources fail safely.
- Verify successful ingestion writes a new version and only activates it after completion. An interrupted or failed ingest leaves the previous active collection usable.
- Restart the service with its persistent volume mounted and verify the active collection remains available. Restart without the volume and verify the service reports a diagnosable state rather than silently claiming the index is populated.
- Measure model download, embedding, retrieval, catalogue fetch, and generation separately. Logs must not contain raw customer questions, PII, credentials, prompts, or secrets.
- Exercise unavailable catalogue API, expired shared key, invalid key, slow catalogue, Groq timeout/rate limit, unavailable Chroma, oversized request, and PHP proxy timeout. The customer receives a useful safe error/handoff; requests remain bounded and recovery is observable.
- Check `/health` separately from readiness/index health. A live process is not proof that the FAQ collection or downstream catalogue/model is ready.

**Proposed RAG release gate:** all safety/privacy and transactional-handoff cases pass; no high-severity fabricated prices/policies; benchmark results are recorded against a known FAQ/index/model version; and an operator confirms ingestion/persistence after deployment. Agree a numeric answer-quality threshold from a labeled baseline before treating it as a service SLO.

### 5.9 Customer pages, PWA, and accessibility

- For home, menu, item detail, cart, checkout, orders, loyalty, promotions, support, and account pages, test loading, populated, empty, validation, offline/network failure, and server-error states.
- Test phone, tablet, desktop, portrait/landscape, zoom/reflow, long names/descriptions, large prices, and narrow chat messages. No essential action or content is hidden behind overlap or horizontal clipping.
- Complete core journeys with keyboard only. Focus must be visible and order predictable; dialogs open, close, and return focus correctly; controls have labels; status updates are announced; errors explain correction.
- Check text contrast, meaningful image alt text, decorative image hiding, screen-reader semantics, reduced-motion settings, and touch target usability. Record browser/assistive-technology combinations tested.
- Test PWA install/update, offline fallback, service-worker activation and cache version change. Verify public menu images cache as intended while account, cart, checkout, payment, and private API responses are not exposed through shared caches.
- Check notification permission denied/granted, subscription revoked/expired, app closed/backgrounded, and supported browser behavior. Push is best-effort; in-app status remains authoritative.

### 5.10 Admin and reporting

- Confirm only admins can manage staff, categories, products, promotions, settings, customers, and reports. Staff may perform only documented staff actions; customers cannot access admin content or APIs.
- Validate create/edit/disable/remove operations, search/filter, empty/error states, list refresh, confirmation dialogs, and persistence after reload.
- Verify image selection, object upload, preview, replacement, invalid format/size, and storage failure. Never accept client-side-only validation as security enforcement.
- Confirm reports use correct date ranges/timezone and aggregation, handle no data, and are consistent with source orders without exposing unnecessary personal or payment data.

## 6. API and persistence contract criteria

For each endpoint affected by a change, record:

- HTTP method/path and authorized actors;
- request schema, allowed bounds, and rejected values;
- response status and `{ success, data, error }` shape;
- ownership and state preconditions;
- database records changed and records that must not change;
- idempotency/retry behavior;
- external calls and failure translation;
- sensitive values that must never be returned or logged.

Test contract compatibility at controller/API boundaries. Clients must receive stable, user-safe errors; internals and stack traces belong in restricted server logs. If a response field changes, update the frontend consumer and integration fixtures together.

## 7. Non-functional criteria

### Performance and capacity

- Keep `backend/tests/load_test.php` as a reproducible public-GET baseline. Record environment, commit, database size, duration, warm-up, concurrency, timeout, sample count, p50/p95/p99, error rate, and endpoint breakdown.
- Do not call a baseline a capacity test or production SLO. Define target latency/error budgets from product needs and measured staging/production baselines, then review thresholds separately from code.
- Add representative read and write workloads before making checkout/order throughput claims. Include database contention, authentication/session, and provider latency separately.
- Measure RAG stages individually and enforce bounded timeouts so PHP proxy, RAG API, catalogue API, and model generation have a coherent end-to-end budget.
- Compare cold start and warm operation; include catalogue cache miss/hit, image cache miss/hit, and post-deploy/restart behavior.

### Reliability and recovery

- Restart frontend/API/RAG processes and confirm health checks, database reconnection, service worker update, and Chroma volume recovery.
- Inject provider/network failures and confirm no partial order, lost payment state, broken staff review, or unbounded retry loop.
- Verify backups can be restored to an isolated environment and schema upgrades can be rolled forward using the documented migration sequence.
- Check logs/metrics support diagnosis by correlation ID/order ID without logging secrets or unnecessary PII. Alerting should distinguish process health, API errors, queue/provider failures, and RAG readiness.

### Security and privacy

- Include authorization, input validation, SQL parameterization, CSRF, CORS, rate limiting, secure cookies, file upload validation, payment token protections, and safe error handling in each relevant suite.
- Inspect service-worker/cache behavior to ensure private responses are never served to another session/user.
- Verify secrets exist only in approved deployment secret storage. Rotate compromised or exposed test credentials; do not repeat them in issue reports.
- Review data retention and deletion paths across SQL, logs, storage, backups, indexes, notifications, and test artifacts. Confirm deletion semantics with the data owner before release.

## 8. Test execution workflow

1. **Clarify the invariant:** write down expected business behavior, edge conditions, actor/role, data changes, and failure behavior before implementation.
2. **Map the change:** identify affected UI, API route/middleware, service/model, database, provider, cache, and documentation boundaries.
3. **Select layers:** add the smallest deterministic test at the owning layer, plus integration/UI evidence where cross-layer behavior changes.
4. **Run local checks:** use the commands below for changed components; never run database-mutating checks until the test database guard and environment are verified.
5. **Review negative paths:** check unauthorized, invalid, stale, duplicate, timeout, rollback, and recovery behavior proportional to the change's risk.
6. **Review diffs and data safety:** inspect test cleanup, fixtures, logs, environment variables, cache keys, generated artifacts, and secrets.
7. **CI:** require every applicable workflow job to pass. Investigate a failure; do not bypass it by weakening assertions without a reviewed explanation.
8. **Staging UAT:** run the relevant checklist in `backend/tests/USER_ACCEPTANCE.md` with test accounts and provider sandboxes.
9. **Release decision:** attach evidence, list known defects/accepted exceptions, and get explicit business acceptance for workflow or policy differences.
10. **Post-deploy smoke:** read-only health, catalogue, login page/static asset, RAG readiness, and notification configuration checks; monitor errors and provider callbacks.

### Local command reference

From the repository root:

```sh
php backend/tests/model_checks.php
php backend/tests/security_checks.php
php backend/tests/database_checks.php
php backend/tests/api_integration_checks.php
```

Start the local backend API in a second terminal after configuring the disposable test DB:

```sh
cd backend/public
php -S 127.0.0.1:8080 router.php
```

For browser tests:

```sh
cd frontend
npm install
npx playwright install chromium
npm run test:ui
```

For RAG unit/retrieval tests, install the service dependencies and run from `RagService`:

```sh
python -m pip install -r requirements.txt
python -m unittest discover -s tests
```

These local checks do not replace the CI MySQL/API workflow or staging UAT. The RAG deterministic suite also runs in CI, while live model, provider, and benchmark quality checks remain staging/operator work.

## 9. CI interpretation and proposed improvements

`.github/workflows/ci.yml` currently has five jobs: PHP unit/security and syntax lint; MySQL database/API integration; customer UI browser checks; deterministic RAG unit/routing checks; and a public-GET load baseline. It uses a disposable MySQL service and synthetic credentials, and it does not call PayFast, Resend, Twilio, Groq, Tavily, or live customer data.

Recommended next test-system improvements, ordered by risk reduction:

1. Add regression cases for maximum RAG response length, active index preservation on failed ingest, and retrieved-content safety boundaries. Current CI covers deterministic routing with Chroma and model generation stubbed; real index and embedding behavior still needs dedicated integration coverage.
2. Expand Playwright coverage for cart quantity limits, admin optional-extra row interactions, live checkout totals, validation/errors, and assistant loading/response wrapping.
3. Expand backend integration checks to cover product-option persistence/selection, actual checkout preview/submit transactions, customer ownership, order snapshots, and staff state transitions.
4. Add deterministic payment/notification provider fakes at service level; retain separate staging sandbox procedures for provider contract and delivery evidence.
5. Add a concurrency test harness for slot reservation, cart/checkout races, order transition locks, and duplicate webhook/idempotency paths.
6. Add a browser accessibility scan and a manual assistive-technology matrix; automated scanning does not replace keyboard/screen-reader evaluation.
7. Publish a labeled RAG benchmark report and performance trend artifact with data/model/index versions. Avoid turning an uncalibrated quality score into a release gate.
8. Add deployment smoke checks for frontend/API/RAG health and persistent volume/index readiness, without mutating production data.

Make each CI expansion isolated, deterministic, secret-free, and bounded in run time. A flaky test must be diagnosed and repaired; retries alone hide instability.

## 10. Evidence, defects, and release gates

For every release candidate, record:

| Field | Required evidence |
|---|---|
| Build | Commit SHA, branch, deployment/environment identifier |
| Scope | Features/routes/services changed and risk classification |
| Automated checks | Commands/workflow run, job result, artifacts or links |
| Manual checks | Tester, date/time with timezone, browser/device/assistive technology, checklist IDs |
| External providers | Sandbox/provider environment, test recipient/account, provider request/status reference with secrets removed |
| Data safety | Test database confirmation, fixture cleanup, no production data mutation |
| Exceptions | Known failures, severity, owner, target resolution, and explicit acceptance authority |
| Release decision | Pass/fail, approver, rollback plan, post-deploy observations |

Suggested severity:

- **Release blocker:** unauthorized access/data exposure; wrong customer charged; order total, discount, or fulfilment materially wrong; duplicate charge/order; unsafe payment callback; data loss/corruption; or core order flow unavailable.
- **High:** a key journey cannot complete, staff cannot safely process orders, RAG fabricates current price/policy in a consequential way, or notifications expose another person's data.
- **Medium:** important feature degradation with a safe workaround and no financial/privacy impact.
- **Low:** cosmetic or low-impact usability issue that does not block operation.

Release only when all required CI jobs pass, all applicable critical UAT cases pass, no unaccepted release blocker/high-severity defect remains, test data is cleaned up, and external-service configuration is confirmed for the deployed environment. A passing test suite is evidence for the covered behaviors and environment, not a guarantee of universal correctness or legal compliance.
