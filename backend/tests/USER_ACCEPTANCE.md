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
