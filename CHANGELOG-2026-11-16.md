# Changelog — 16 November 2026

This entry records the application changes made on 16 November 2026. It describes repository work only; it does not imply that the changes were deployed or applied to the Railway production database.

## Business settings and reporting

- Connected the admin business-settings page to the backend settings endpoint for delivery and collection settings, weekly trading hours, ordering status, business contact details, minimum order amount, free-delivery threshold, and cart quantity limits.
- Added backend enforcement for the configured per-item and total-cart quantity limits. Defaults are six units of one menu item and ten cart units overall.
- Added a more detailed business reports page with customer and order insights.
- Added a MySQL schema preflight query and a one-time settings repair migration for existing databases. The preflight must be run against the database used by the backend; remove any already-present column additions before applying the migration.

## Cart, checkout, and promotions

- Added meal recommendations for the empty-cart page.
- Updated checkout to show the cart item summary before the price breakdown and keep the review total visible as checkout inputs change.
- Corrected the delivery-fee breakdown when free delivery applies and aligned menu price previews with backend percentage and fixed-amount promotion calculations.
- Enforced that a menu item cannot have overlapping active promotions.
- Corrected BOGO pricing and fulfillment: a BOGO promotion targets exactly one menu item, grants one additional unit of that item, includes the free unit in the displayed/order subtotal, and offsets it with the BOGO discount. This keeps the customer's charge equal to the paid cart value while charging all unrelated items normally.
- Prevented the order details response from adding a duplicate BOGO unit to quantities already stored for fulfillment.

## Customer support

- Added customer-side support form lookups for orders belonging to the signed-in customer and for menu items. Support requests can reference the selected order or product.

## Remaining work and deployment status

- At the time of the original commits, the four-minute assistant cooldown was not implemented.
- At the time of the original commits, customer cancellation with an automatic PayFast refund of confirmed charges was not implemented.
- BOGO application still depends on a promotion being selected/applied at checkout; automatic application solely from having the promoted item in the cart is not recorded as completed here.
- At the time of the original commits, checkout rejected carts below the configured minimum order amount, contrary to the requested non-blocking behavior.
- The settings migration has not been run against Railway in this workspace. Run the schema preflight against the production database and apply only the missing-column changes before expecting the settings save endpoint to work there.

## Verification

- PHP syntax checks passed for the promotion, checkout, order, and admin-settings code changed for BOGO behavior.
- No automated test suite or Railway production database migration was run for this entry.

## Follow-up review corrections

- Checkout now treats the configured minimum as an advisory in the final preview; it does not block preview or order submission.
- Customer cancellation now requests a full remaining PayFast refund for paid or preparing orders in production mode. Orders are frozen while the refund is in flight, duplicate refunds are guarded, and pending settlement is reported accurately. Sandbox remains disabled for automatic refunds. Bank payout fields are validated against PayFast's returned bank list and are not saved by Food by K.
- Staff and customers receive cancellation notifications. Staff advancement and adjustment are blocked while a customer refund is pending.
- Assistant requests are limited to one per client every four minutes, with a concurrency guard.
- Existing carts and staff-adjusted orders can be reduced after admins lower limits; increases still follow configured caps.
- Updated model checks and staging acceptance criteria cover the corrected BOGO quantity rules, advisory minimum, cart-limit recovery, assistant cooldown, and production-only refund path. These follow-up changes have not been deployed or runtime-tested.
- BOGO now grants one matching free unit for every paid qualifying unit, with paid/free quantities and full free-item value shown in checkout. Targeted promotion codes fail when the cart contains no eligible item; checkout and the order record continue to accept only one code. The active promotion feed hides conflicting legacy assignments and rejects their use, while admin edits already prevent overlapping active offers for the same menu item.
- Order items store free-unit counts explicitly so receipts, staff review and later adjustments do not infer BOGO quantities from fulfilled totals. Existing databases need the one-time `backend/foodbyk/database/order_item_free_quantity.sql` migration before this model is deployed.
