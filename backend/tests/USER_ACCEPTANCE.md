# User acceptance test checklist

These scenarios require a real browser, configured backend, and business
decisions from the Food by K team. They are a manual release checklist, not
automated CI tests. Run them against staging with test customer/staff accounts
and PayFast sandbox credentials; never use live payment credentials.

Record the build/commit, tester, date, browser/device, result, and issue link
for each scenario. A release candidate passes when every applicable scenario
passes or has an explicitly accepted issue.

## Customer ordering

- [ ] Browse the home page and menu signed out; category selection and search
  show products, empty results, and an understandable error when the API is
  unavailable.
- [ ] On a narrow phone viewport, menu cards remain usable without horizontal
  overflow; skeletons clear after products load.
- [ ] Add products to cart, change quantities, remove a product, and confirm
  the displayed total matches the remaining item quantities and prices.
- [ ] With two items in the cart, confirm loading placeholders disappear and
  exactly two real cart rows remain.
- [ ] Checkout as collection; choose a date and available time, review the
  total, then submit through PayFast sandbox.
- [ ] Checkout as delivery using a saved, located address; verify delivery
  fee, selected address, and time slot in the review and submitted order.
- [ ] Try a missing/unlocated address, unavailable time, invalid promotion,
  empty cart, and a declined/failed API response; each must provide a clear
  next step and must not create an unintended order.
- [ ] After PayFast returns, verify the newest order is shown, the status
  updates while the page remains open, and collection and delivery use
  distinct progress language (including “Out for delivery” for delivery).
- [ ] Cancel a submitted order and verify it disappears/updates after server
  confirmation; verify cancellation is unavailable after the order leaves
  the pending/submitted state.
- [ ] Complete an order in staging and verify it leaves the current-order
  page, the thank-you dialog appears once, and the empty state offers a menu
  link on a later visit.

## Account and accessibility

- [ ] Update name, email, phone, and structured street/city/province/postal
  address; reload and confirm persisted values populate every field.
- [ ] Edit, set default, and remove an address; confirm delivery checkout
  offers only the expected saved addresses.
- [ ] Check loading, empty, validation, and server-error states with keyboard
  navigation and a screen reader; status updates should be announced.
- [ ] Enable reduced motion and confirm skeleton animation is disabled.
- [ ] Check customer pages at phone, tablet, and desktop widths for clipped
  content, focus visibility, readable contrast, and usable controls.

## Staff, admin, and security-sensitive flows

- [ ] Verify an admin can invite staff, complete the invite, and staff can
  sign in; a customer cannot access staff/admin pages or endpoints.
- [ ] Have staff confirm, adjust, and decline separate sandbox orders; ensure
  status history, customer-facing progress, and notifications match each
  action.
- [ ] Confirm a declined order is not charged and a confirmed order is charged
  only after acceptance; check PayFast sandbox callbacks and duplicate
  callback handling.
- [ ] Verify WhatsApp sandbox messages only reach joined allowlisted test
  participants, and email trial messages only go to the verified trial
  recipient. Do not treat provider delivery as an automated CI assertion.
- [ ] Verify production secrets are configured only in deployment secret
  storage and that production error responses/logs do not expose tokens,
  passwords, or database credentials.

## Product options and promotions

- [ ] In admin, create a product with no extras, a free extra, a priced extra,
  and several extras. Edit the product and confirm each extra appears in its
  own name/price row; add and remove rows; use keyboard-only navigation.
- [ ] Try a partially completed extra row, a negative amount, a malformed
  amount, and a free extra entered as `0.00`. Invalid rows must explain the
  correction and must not be saved.
- [ ] Select free and paid extras on a customer menu item. Confirm the displayed
  amount, cart line, checkout preview, submitted order snapshot, and staff
  order review contain the same selected extras and calculated prices.
- [ ] Tamper with an option ID or submit an option belonging to another product
  or one that is no longer available. The API must reject it and keep the
  existing cart/order unchanged.
- [ ] Exercise percentage, fixed-amount, BOGO, and free-delivery promotions
  against both targeted and non-targeted products, mixed baskets, expired and
  inactive dates, delivery and collection, and edge quantities.
- [ ] Confirm every eligible menu item shows its offer label; ineligible items
  do not. Confirm percent/fixed-price presentation, code requirements, and
  BOGO/free-delivery wording agree with the checkout calculation.
- [ ] Open the customer promotions page and verify targeted product images and
  names match each offer. Simulate a missing image and confirm the offer remains
  readable and usable.
- [ ] Combine a promotion with loyalty redemption and verify the subtotal,
  delivery fee, promotion discount, points value, and final amount are correct
  in preview, order details, and staff review.

## RAG assistant and ingestion

- [ ] Ask the FAQ a representative set of business questions in formal, casual,
  misspelled, and paraphrased language. Record the question, expected source,
  answer, correctness, grounding, and any unsupported claims.
- [ ] Ask a full-menu, cheapest-item, ingredient, and budget question. Confirm
  current catalogue names/prices/availability are used and unavailable items
  are not recommended.
- [ ] Ask about order status, payment status, refund, account data, or an
  address-specific delivery decision. Confirm the assistant hands the customer
  to the correct authenticated workflow rather than claiming to execute it.
- [ ] Ask an unsupported question, include personal data, and try prompt
  injection. Confirm the answer is safe, no private data is exposed, and logs
  do not contain the submitted question or secrets.
- [ ] With a test index, ingest updated FAQ content and restart the service with
  its Railway volume mounted. Confirm the new index is active only after
  successful completion and the prior active index remains available if ingest
  is interrupted.
- [ ] Simulate slow/unavailable catalogue, model, and RAG service responses.
  Confirm progress/error behavior is understandable, requests time out within
  the agreed budget, and the customer can retry or use the menu directly.

## Notifications, PWA, and resilience

- [ ] Place a test order and verify a staff notification identifies the order,
  customer, items/quantities, fulfilment/time, and total. Verify customer
  confirmed, declined, payment-success, and payment-failure notices show the
  correct event and amount.
- [ ] Reply to a test support ticket and confirm only its owning customer is
  notified. Test an expired push subscription and provider rejection; the ticket
  or order status change must still be saved.
- [ ] Verify staff browser notification sound/popup behavior with a supported
  browser, granted permission, active/inactive tab, and a newly submitted order.
  Record browser/OS restrictions that affect sound or display.
- [ ] Verify production WhatsApp template wording/placeholders in Twilio
  sandbox/approved test configuration. Do not assume the application can
  change the content of an already approved template by changing PHP alone.
- [ ] Load menu images twice and confirm cache behavior. After image replacement
  or a service-worker update, confirm customers receive the current image and
  private account/cart/checkout API responses are not cached for another user.
- [ ] Simulate API, database, object-storage, email, WhatsApp, and push failures
  independently. Confirm order integrity is preserved, the UI explains what
  failed, and logs provide enough redacted detail for diagnosis.

## Evidence and release sign-off

- [ ] Record the commit SHA, environment, tester, date/time and timezone,
  browser/device, checklist results, provider sandbox references, and links to
  defects or accepted exceptions.
- [ ] Confirm all test records were created in staging and cleaned up; no live
  payment, customer message, or production data mutation was performed.
- [ ] Confirm every release-blocking/high-severity failure is fixed or formally
  accepted by the accountable business owner, and record rollback and
  post-deployment smoke-check steps.
