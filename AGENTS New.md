# AGENTS.md — Food by K Backend (current state)

This file replaces any earlier `AGENTS.md`/`DOMAIN.md` you may have seen —
a lot changed since those were written (roles table, product status,
actual repo structure, an unresolved order-flow question). Treat this as
the only current source of truth. **This file goes stale too** — if
something here contradicts what's actually in the repo (model
`fromRow()`/`toArray()` methods especially), the code wins; flag the
contradiction rather than silently trusting this document.

Repo: `github.com/Reaper5324/FoodByK_One`

## 1. What this system is

Food by K is a fast-food vendor (burgers, kotas, fries, wings) currently
taking orders manually via Instagram/social media. This is a mobile-first,
database-driven **pre-ordering platform**: customers browse a menu,
register/log in, add to cart, choose collection or delivery, and submit a
pre-order. Backend: plain PHP (no framework), MySQL/MariaDB, Active
Record models, Controller → Service → Model → Database layering.

## 2. ⚠️ Unresolved — do not build around this without asking

Two different order-flow models have been discussed for this project:

- **A) Staff-approval model (what's actually built)**: customer submits an
  order → card tokenized (PayFast Ad Hoc Tokenization, nothing charged
  yet) → **staff must confirm, adjust, or decline** before anything else
  happens → confirm triggers the actual charge → prepare → ready →
  complete. This is what `Order`, `Payment`, `OrderService`, and every
  controller/middleware built so far assumes. FR-08/FR-09 require this.
- **B) Capacity-model (proposed once, never confirmed)**: no staff
  approval step at all — orders go straight to "preparing" gated only by
  a per-time-window capacity check, and payment is captured on
  *completion* instead of on staff confirmation.

**Model A is what exists in the codebase right now.** Model B was
presented mid-conversation from a source outside this thread and directly
contradicts FR-08/FR-09 and everything built since. It was never resolved
either way. **If asked to build or modify order-flow logic, default to
Model A** and flag the conflict rather than guessing which one is
current — this is the single highest-impact open question in the project.

## 3. Current repo state (as last verified — check before trusting)

**Models — written and in good shape:**
`User`, `Customer`, `Staff`, `Admin`, `Role`, `Category`, `Product`,
`CartItem`, `Address`, `BusinessSettings`, `Order`, `OrderItem`,
`OrderStatusHistory`, `Payment`, `Promotion`, `PasswordReset`,
`Database`, `model.php` (base Active Record class).

**Services — written:** `AuthService`, `CartService`, `ProductService`,
`PromotionService`.

**Services — designed in conversation, not yet confirmed committed:**
`OrderService`, `PaymentService`, `DeliveryService`,
`NotificationService`. Check the actual files before assuming these are
still empty — they may have been pasted in since.

**Core / Middleware / Controllers — designed in conversation
(`Request`, `Response`, `Router`, `Middleware` interface,
`AuthMiddleware`, `RoleMiddleware`, `CsrfMiddleware`,
`RateLimitMiddleware`, `Controller` base, `AuthController`,
`CartController`, `CategoryController`, `ProductController`,
`PromotionController`, `CheckoutController`, `OrderController`,
`PaymentController`, `AdminController`, `HealthController`,
`routes.php`) — not yet confirmed committed to the repo. Check before
assuming these exist.

**`bootstrap.php` was empty as of last check** — if it still is, nothing
in the app can run end-to-end (no autoloader wired up) regardless of how
much other code exists.

## 4. Resolved architecture decisions

- **Active Record**, not Repository/Factory/ORM — deliberate choice for
  team size and timeline. Each model owns its own persistence
  (`save()`/`delete()`/`findById()`) plus small domain logic
  (`Order::canTransitionTo()`, `Payment::markSuccessful()`).
- **Controller → Service → Model → Database.** Controllers are thin:
  parse request, call one service method (or a trivial model finder for
  simple reads — see `ProductController::index()`/`CategoryController`),
  return the result. Services own transactions and multi-model
  workflows. Models never open a transaction or call another model's
  service.
- **Services return `['success' => bool, 'data' => mixed, 'error' =>
  ?string]` uniformly.** `Response::fromService()` is the one place this
  gets translated to an HTTP status code — services don't know about
  HTTP, controllers supply the status codes per call.
- **`User → Customer/Staff/Admin` via a normalized `roles` table**
  (`users.role_id` FK → `roles.id`), **not** an ENUM column. `Customer`,
  `Staff`, `Admin` each have their own `find*ById()` that joins `roles`
  and checks `role_name` — a plain `User::findById()` does **not** join
  roles, so `$user->role` is empty/unreliable on a bare `User`. Always
  resolve role via `Role::findById($user->role_id)` when you only have a
  bare `User` (see `AuthMiddleware`, `AuthService::publicUser()`).
- **Single `orders` table**, no separate `delivery_orders` subtype table.
  `address_id`/`distance_km`/`delivery_fee` are nullable/zero for
  collection orders. A `CHECK` constraint (once the migration is
  finalized) enforces delivery ⇒ address present, collection ⇒ address
  absent.
- **Order concurrency**: `SELECT ... FOR UPDATE` (`Order::lockById()`)
  inside a DB transaction — not optimistic locking/version columns.
  Every state-changing `OrderService` method follows: begin → lock →
  validate transition via `canTransitionTo()` → mutate → write
  `order_status_history` → commit.
- **Payment: PayFast Ad Hoc Tokenization**, not a standard redirect
  payment. Flow: order created (provisional) → customer redirected to
  set up a R0 tokenization agreement → PayFast ITN webhook delivers a
  **token** (`Payment.status = tokenized`, nothing charged) → staff
  confirm → `PaymentService::chargeToken()` calls
  `POST /subscriptions/{token}/adhoc` → a **second**, asynchronous ITN
  webhook confirms success/failure → only then does `Order.status`
  become `paid`. Decline/cancel before charge just voids the token — no
  PayFast charge call ever happens on that path.
  **PayFast signature generation/verification is still a draft** — field
  ordering/encoding needs checking against PayFast's live docs before
  production; don't treat existing signature code as final.
- **No public staff/admin registration.** `/auth/register` only ever
  creates `role = customer`. Staff/Admin accounts are created by an Admin
  via `AuthService::createStaffAccount()`, which sets an unusable random
  password and issues an invite link through the same `PasswordReset`
  mechanism used for forgotten passwords — one mechanism, two purposes.
- **One login endpoint for everyone**, including admin — no separate
  admin login system. Role-based access is enforced entirely by
  `RoleMiddleware` after authentication, not by a different login path.
- **Contextual auth**: browsing (menu, categories, product detail,
  search, active promotions) requires no account at all. The moment a
  customer hits a gated action (add to cart, checkout), the route's
  `AuthMiddleware` returns 401 and the frontend is expected to prompt
  login/register at that point, not before.
- **CSRF**: session-stored token compared against `X-CSRF-Token`
  header or `csrf_token` body field on non-safe HTTP methods.
- **Rate limiting**: backed by a `login_attempts` DB table, not an
  in-memory counter — PHP is stateless per-request under both the
  built-in server and php-fpm, so in-memory counters don't work here.

## 5. Known schema drift — migration.sql has not been written/run yet

The person building this has deliberately not committed a migration file
yet because the schema is still moving. **Do not treat any earlier
migration SQL from this conversation's history as current** — the actual
model `fromRow()`/`toArray()` methods are the real source of truth for
column names right now. Known drift to reconcile whenever the migration
actually gets written:

- `roles` table (id, role_name) + `users.role_id` FK — no ENUM.
- `users` has `name` (not `full_name`), plus `profile_picture`,
  `address`, `city`, `province` — worth a direct question about whether
  address/city/province belong on `users` at all given the dedicated
  `addresses` table already exists for exactly this.
- `products` has a `status` enum (`active`/`inactive`/`removed`)
  *alongside* `is_available` — both are checked together in
  `CartService::addItem()`.
- `login_attempts` table (identifier, attempted_at) needed for
  `RateLimitMiddleware`.
- `payments.gateway_token` needs encryption at rest — it's a bearer
  credential capable of charging the customer, even though it isn't raw
  card data.

## 6. Confirmed bugs / cleanup still open

- **Duplicate `Database` class** in both `models/Database.php` and
  `database/Database.php` (identical content). Delete one — keep
  `database/Database.php`, it's the more correct home.
- **`models/PasswordRest.php` filename typo** — the class inside is
  correctly named `PasswordReset`. Harmless while `bootstrap.php` is
  empty, but will break the moment filename-based autoloading exists.
  Rename the file to `PasswordReset.php`.
- **`Admin::addPromotion()` is stale** — still assumes the old
  two-discount-type `Promotion` shape. The real `Promotion` model
  supports four types including `buy_one_get_one`, which needs more
  parameters than this method currently accepts. Needs revisiting
  against `Promotion.php`'s actual constructor before it's reliable.
- **`config/config.php`** only defines `DB_*` constants —
  `PAYFAST_MERCHANT_ID`/`PAYFAST_MERCHANT_KEY`/`PAYFAST_PASSPHRASE`/
  `PAYFAST_SANDBOX`/`PAYFAST_RETURN_URL`/`PAYFAST_CANCEL_URL`/
  `PAYFAST_NOTIFY_URL` all still need adding before `PaymentService` can
  run.
- **`Review.php`** exists in the repo but is intentionally out of scope —
  carried over from an unrelated earlier project, not tied to any FR.
  Don't build features around it unless explicitly asked.

## 7. Functional requirements (condensed, current)

| Area | Requirement |
|---|---|
| Browsing | Menu/category/product browsing and search require no account |
| Accounts | Register/login required before cart or order (customer self-service only — see §4 on staff/admin provisioning) |
| Fulfilment | Customer chooses Collection or Delivery |
| Delivery eligibility | Configurable radius, **inclusive** boundary, Haversine distance from a one-time-geocoded address |
| Trading hours | Requested fulfilment window must itself fall within configured trading hours |
| Staff review | Staff confirm / adjust / **decline** every order (see §2 — this is the crux of the unresolved question) |
| Payment | Card tokenized at submission; actual charge only on staff confirmation (§4) |
| Promotions | Discount locked at submission, re-validated if order is adjusted before confirmation |
| Loyalty | Tracked against the customer's account |
| Admin | Manage products, categories, promotions, business settings, staff accounts |
| Notifications | Staff notified (email/WhatsApp/SMS) on new order |

## 8. Folder structure (current)

```
FoodByK_One/
├── .github/workflows/ci.yml
├── backend/
│   ├── public/
│   │   ├── .htaccess
│   │   └── index.php
│   └── foodbyk/
│       ├── bootstrap.php
│       ├── composer.json
│       ├── config/config.php
│       ├── core/            (Router, Response, Request)
│       ├── database/        (Database.php)
│       ├── middleware/
│       ├── models/
│       ├── services/
│       └── controllers/
├── frontend/
└── tests/
    └── model_checks.php     (lightweight plain-assertion tests, no PHPUnit)
```

## 9. What actually needs to happen next, roughly in order

1. Resolve §2 (staff-approval vs. capacity model) — everything else is
   downstream of this.
2. Wire up `bootstrap.php` (autoloader) so the app can run at all.
3. Reconcile and write the actual `migration.sql` against §5.
4. Fix the confirmed bugs in §6.
5. Add the missing `PAYFAST_*`/geocoding/Twilio constants to
   `config/config.php`.
6. Confirm which of the "designed but not confirmed committed" files in
   §3 actually exist in the repo, and paste in whichever don't.
