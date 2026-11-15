# Changelog — 15 November 2026

This entry records the Food by K application work completed during the sessions on 15 November 2026. Code changes are in the repository; deployment and external provider configuration are noted where they still require action.

## RAG assistant

- Reworked the RAG service to improve retrieval, product-catalogue answers, FAQ ingestion, and responses to everyday customer wording.
- Expanded the public Food by K FAQ with business information, policies, promotions, ordering, payment, delivery, collection, and loyalty details.
- Improved ingestion reliability with batched embedding work, progress output, persistent data-directory support, and guidance for running ingestion in the Railway service.
- Added evaluation cases and retrieval coverage for common customer questions.
- Added visible assistant activity steps while answers are being prepared and improved chat text wrapping so responses stay inside the chat bubble.
- Added a fast path for full-menu questions: it reads the live product catalogue and answers directly instead of waiting for unnecessary retrieval and LLM work.
- The Railway ingestion output shared during the session reported 64 FAQ chunks stored. Re-run ingestion after future FAQ or source changes.

## Customer menu, cart, and checkout

- Added product customizations/optional extras across product setup, cart, checkout, order items, and order display, with server-side validation and price calculation.
- Added cart quantity increase/decrease controls while respecting product and cart quantity limits.
- Adjusted the mobile menu card layout so the Add to Cart control does not cover the product price.
- Made checkout totals visible and responsive to changes as customers edit their order.
- Added browser caching for menu product images loaded from object storage.
- Improved product promotion handling so applicable discounts and offers are reflected on menu items and checkout totals.

## Promotions

- Extended promotion application and checkout calculations for targeted products and offer types, including percentage, fixed-amount, buy-one-get-one, and free-delivery promotions.
- Added order and checkout details that identify eligible products and promotion discounts.
- Updated menu promotion matching so active offers display on applicable products, including free-delivery offers and a product-name fallback when target IDs do not line up.
- Added product images and links for targeted items on the customer promotions page.

## Staff and admin tools

- Improved admin pages with live API-backed product, category, promotion, customer, staff, order, settings, and reporting data/actions.
- Enhanced order review with product images, customization details, and information about products eligible for promotions.
- Added operational order review and adjustment flows, status handling, and supporting order/customer details.
- Added an easier optional-extras editor in the product form: separate name and price boxes, add/remove controls, existing-extra editing, and `0.00` for free extras.
- Added analytics/reporting details for orders and promotions.

## Notifications and installable app support

- Improved staff order alerts and customer order/support updates through push notification handling, including notification titles/tags and staff/customer routing.
- Enriched order email, SMS, and WhatsApp Sandbox messages with item quantities and prices, fulfilment timing, delivery fees, promotion and loyalty discounts, order totals, and payment status. Staff messages also include customer contact details.
- Added optional configuration for richer approved Twilio WhatsApp templates. Production WhatsApp templates are managed by Twilio; create and approve templates with `{{1}}` (order number) and `{{2}}` (order summary), then set the corresponding `*_RICH` Railway variables to use them. Existing template IDs remain the fallback.
- Updated the service worker for notification delivery and asset caching, and set the application favicon to the navigation-bar brand image.

## Verification and deployment notes

- Added retrieval and promotion calculation coverage alongside the implementation changes.
- `git diff --check` passed for the changes reviewed in these sessions. Some local checks could not run because the workspace lacked PHP, Node.js, and the installed RAG Python dependencies.
- The latest changes are repository code; deploy the relevant frontend/backend/RAG services for customers to receive them. Rich production WhatsApp content additionally needs approved Twilio templates and their SIDs configured in Railway.
