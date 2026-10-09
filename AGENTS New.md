# Agent Notes — Food by K

This file is a short project orientation for coding agents. The canonical
instructions are [AGENTS.md](AGENTS.md), and the authoritative business rules
are in [DOMAIN.md](DOMAIN.md). If this note conflicts with either, follow the
canonical files and the current implementation.

## Current system

Food by K is a mobile-first ordering platform with a PHP/MySQL JSON API and a
separately hosted customer frontend. The customer frontend is served as static
HTML, CSS, and JavaScript (Netlify); it cannot execute PHP. The repository also
contains legacy PHP templates/components and admin assets, so check the target
page before choosing an implementation format.

```text
frontend/src/index.html                 customer home
frontend/pages/customer/                menu, cart, checkout, current order
frontend/pages/auth/                    login, registration, account, password flows
frontend/public assets/js/api/           shared JSON API client and auth helpers
backend/public/                          PHP API entry point
backend/foodbyk/                         controllers, services, models, schema, config
```

## Customer experience rules

- Customer pages should remain mobile-first and use the shared Food by K
  visual language from the home page.
- The customer orders page shows only the newest/current order. Completed
  orders leave that view; a separate order-history feature is future work.
- Collection and delivery have distinct progress labels (for example,
  “Ready for collection” versus “Out for delivery”).
- The account page edits customer details and structured delivery address
  fields. Preserve address components expected by the backend rather than
  storing an unstructured free-form address only.
- Use loading placeholders for customer data that is fetched asynchronously;
  remove them when results, empty states, or errors are rendered.
- Preserve accessible labels/status announcements and reduced-motion support
  when changing loading or status UI.

## Backend and payment

Follow the Controller → Service → Model → Database structure and order-state
concurrency rules in `AGENTS.md`. For order lifecycle and PayFast Ad Hoc
Tokenization rules, read `DOMAIN.md` before changing code. Never put secrets in
source control or documentation; use deployment environment variables.

For local commands, database setup, and CI checks, use the current
`backend/foodbyk/README.md`. Verify files and configuration in the repository
before relying on older conversation notes.
