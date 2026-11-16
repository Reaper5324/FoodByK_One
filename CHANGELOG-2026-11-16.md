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

- A four-minute server-side rate limit for support queries is not included in this entry.
- Customer-initiated cancellation with an automatic PayFast refund of confirmed charges is not implemented here. The existing staff refund flow is separate and should not be described as customer cancellation automation.
- BOGO application still depends on a promotion being selected/applied at checkout; automatic application solely from having the promoted item in the cart is not recorded as completed here.
- The current checkout validation rejects carts below the configured minimum order amount. The requested behavior that the minimum be applied last without blocking checkout remains unresolved.
- The settings migration has not been run against Railway in this workspace. Run the schema preflight against the production database and apply only the missing-column changes before expecting the settings save endpoint to work there.

## Verification

- PHP syntax checks passed for the promotion, checkout, order, and admin-settings code changed for BOGO behavior.
- No automated test suite or Railway production database migration was run for this entry.
