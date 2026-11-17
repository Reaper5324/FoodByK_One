Codex Implementation Task: FoodByK Security, Validation & Business Logic Guardrails
You are working on the FoodByK repository:
https://github.com/Reaper5324/FoodByK_One
Your task is to audit, implement, and test a comprehensive set of application guardrails across the existing FoodByK platform.
This is not a request to redesign the application or rewrite its architecture. Strengthen the existing implementation while preserving established functionality, business rules, API contracts, and customer experience.
1. Non-negotiable development rules
Before making changes:
1.	Inspect the repository structure and understand the existing architecture.
2.	Read ARCHITECTURE.md, TESTING_GUIDE.md, backend documentation, relevant service classes, controllers, middleware, models, migrations, and frontend JavaScript.
3.	Identify existing protections before implementing new ones.
4.	Reuse existing validation, service, middleware, and database patterns wherever practical.
5.	Do not introduce unnecessary frameworks, dependencies, abstractions, or architectural rewrites.
6.	Do not duplicate validation logic across multiple files when a shared validator or service is appropriate.
7.	Do not remove existing functionality simply because it complicates implementation.
8.	Do not weaken existing security controls to make tests pass.
9.	Do not hardcode credentials, secrets, environment-specific URLs, or business settings.
10.	Never trust frontend validation as a substitute for backend validation.
Customer experience preservation
The customer-facing interface is the reference implementation for the platform's intended appearance and behaviour.
•	Make minimal changes to customer pages.
•	Do not redesign customer pages.
•	Do not unnecessarily change layouts, colours, typography, navigation, or component structure.
•	Improve validation feedback without disrupting existing workflows.
•	Staff and admin interfaces may receive necessary consistency and usability improvements, but visual redesign is outside this task.
The goal is stronger application integrity, not a different application.
2. Implementation process
Work in the following order.
Phase 1 — Audit
Inspect the current implementation and produce an internal checklist of:
•	Existing protections.
•	Partially implemented protections.
•	Missing protections.
•	Potential regressions or compatibility risks.
Classify every requirement as:
•	EXISTING — correctly implemented and tested.
•	PARTIAL — implemented but incomplete or insufficiently tested.
•	MISSING — not implemented.
•	NEEDS INVESTIGATION — cannot be confidently verified.
Do not overwrite working protections with alternative implementations.
Phase 2 — Implement
Implement missing and incomplete guardrails in the phases below.
Work incrementally. Keep changes focused and logically grouped.
Phase 3 — Test
Add or update automated tests for every meaningful guardrail.
Run relevant existing tests after each implementation phase. Run the full available test suite before finishing.
Phase 4 — Report
Provide a final implementation report identifying:
•	What was already protected.
•	What was implemented or strengthened.
•	Files changed.
•	Database migrations added.
•	Tests added and executed.
•	Test results.
•	Outstanding risks or incomplete work.
Do not claim that a feature is secure merely because its validation exists. Demonstrate the relevant behaviour through tests.
 
3. Phase A — Input validation and cart integrity
Priority: Critical
A1. Cart quantity validation
Review:
•	frontend/public/assets/js/cart.js
•	backend/foodbyk/services/CartService.php
•	Related cart controllers, models, and endpoints.
Cart quantities must:
•	Be whole numbers.
•	Be greater than or equal to 1 when adding or updating an item.
•	Never be negative.
•	Never accept decimals.
•	Never accept empty values, malformed numeric strings, scientific notation, or values outside supported integer limits.
•	Respect the existing configurable per-item and total-cart quantity limits.
Important:
Do not simply cast arbitrary input to an integer before validating it. Strictly validate the original request value to prevent malformed input from being silently converted into a valid-looking quantity.
For example:
Input	Expected result
1	Accept
6	Accept if within configured limits
0	Reject as an update
-1	Reject
1.5	Reject
abc	Reject
Empty string	Reject
Extremely large integer	Reject
1e3	Reject
A2. Separate updating and deleting cart items
Review the current behaviour of CartService::updateQuantity().
If zero or negative quantities currently cause an item to be deleted:
•	Remove this implicit deletion behaviour.
•	An update request must accept only valid positive quantities.
•	Deletion must use an explicit delete operation.
•	Ensure the frontend calls the correct operation.
A malformed update must never unexpectedly remove a customer's cart item.
A3. Cart ownership and concurrency
Ensure:
•	Customers can only modify their own cart.
•	Cart item IDs cannot be used to modify another customer's cart.
•	Cart limits are enforced server-side.
•	Concurrent cart modifications cannot bypass quantity limits.
•	Different product customisation combinations are included in total cart quantity calculations.
•	Cart writes remain atomic.
A4. Shared validation
Where appropriate, implement or improve reusable validators for:
•	Positive integers.
•	Non-negative numbers.
•	Monetary values.
•	Boolean values.
•	Strings and maximum lengths.
•	Email addresses.
•	Phone numbers.
•	Dates and times.
•	Enumerated values.
•	Database-backed IDs.
•	Arrays and nested request objects.
Avoid excessive abstraction. Validators should be simple, readable, and reusable.
A5. Unexpected request fields
Review API endpoints for mass assignment vulnerabilities.
•	Explicitly allow fields that each endpoint accepts.
•	Do not blindly pass arbitrary request objects into models.
•	Never allow customers to set their own roles, payment states, order statuses, ownership IDs, or trusted financial values.
 
4. Phase B — Product, pricing and checkout integrity
Priority: Critical
Review:
•	ProductService.php
•	ProductOptionService.php
•	CheckoutService.php
•	Related product, option, cart, and order models.
B1. Product and option validation
Ensure:
•	Product prices cannot be negative.
•	Option prices cannot be negative.
•	Monetary values support no more than two decimal places where applicable.
•	Product and option IDs exist.
•	Options belong to the selected product.
•	Unavailable or disabled options cannot be selected.
•	Duplicate customisations are handled according to the existing business rules.
•	Existing maximum option counts and string-length limits are enforced.
•	Database constraints support the application-level rules.
B2. Server-authoritative pricing
The backend must calculate all financial values.
Never trust customer-supplied:
•	Product unit prices.
•	Customisation prices.
•	Subtotals.
•	Delivery fees.
•	Discounts.
•	Loyalty discounts.
•	Promotion values.
•	BOGO free quantities.
•	Final order totals.
The backend must resolve product and option prices from trusted database records.
Use safe monetary calculations. Avoid floating-point arithmetic for financial calculations where it could cause rounding errors. Prefer integer cents or appropriate decimal arithmetic, consistently with the existing database design.
B3. Checkout recalculation
At checkout, independently recalculate:
1.	Product availability.
2.	Product prices.
3.	Selected option validity.
4.	Option prices.
5.	Quantities.
6.	Eligible promotions.
7.	Loyalty discounts.
8.	Delivery fees.
9.	Minimum order requirements.
10.	Final payable total.
Do not assume that a cart is still valid because it was valid when the customer first added the items.
If prices, availability, promotions, or fulfilment costs change between cart review and checkout, return a clear response allowing the customer to review the updated order before payment.
B4. Order snapshots
Ensure orders retain reliable historical snapshots of:
•	Product name.
•	Product price.
•	Selected customisations.
•	Customisation prices.
•	Quantities.
•	Discounts.
•	Delivery fees.
•	Final amounts.
Subsequent product edits must not silently change the financial history of existing orders.
 
5. Phase C — Promotions and loyalty
Priority: High
Review promotion and loyalty implementations.
C1. Promotion validation
Ensure the backend validates:
•	Promotion activation status.
•	Start and expiry dates.
•	Eligible products.
•	Minimum order requirements.
•	Percentage discount boundaries.
•	Fixed discount boundaries.
•	Free delivery eligibility.
•	BOGO eligibility.
•	Customer eligibility where applicable.
•	Usage limits where implemented.
Percentage discounts must not exceed 100%.
Fixed discounts must not create negative totals.
Promotion calculations must use authoritative database prices and must be repeated at checkout.
C2. BOGO integrity
BOGO logic must be calculated by the backend.
•	Customers must not be able to manipulate free quantities.
•	Paid and free quantities must be calculated correctly.
•	The order snapshot must represent the actual quantity the kitchen needs to prepare.
•	Customer receipts and staff order views must remain consistent.
•	Promotion eligibility must be checked again before payment.
C3. Loyalty
Ensure:
•	Customers cannot modify their own loyalty balance.
•	Loyalty redemption cannot exceed the available balance.
•	Loyalty balances cannot become negative.
•	Points are awarded only after the relevant successful business event.
•	Duplicate payment callbacks cannot award points twice.
•	Refunds and cancellations follow the existing loyalty reversal policy.
 
6. Phase D — Delivery, collection and scheduling
Priority: Critical
Review:
•	DeliveryService.php
•	SlotService.php
•	CheckoutService.php
•	Relevant address, settings, and order components.
D1. Fulfilment validation
Only explicitly supported fulfilment methods should be accepted.
Validate the combination of:
•	Delivery or collection.
•	Customer address.
•	Delivery availability.
•	Delivery fee.
•	Delivery radius.
•	Selected collection or delivery slot.
Delivery must require a valid customer-owned address.
Collection must not require an unnecessary delivery address.
D2. Address integrity
Ensure:
•	Address IDs belong to the authenticated customer.
•	Coordinates are validated server-side.
•	Latitude and longitude are within valid bounds.
•	Coordinates are supplied together where required.
•	Delivery radius is checked using trusted business settings.
•	Delivery fees are calculated by the backend.
•	Customer-supplied delivery fees are ignored.
D3. Slot validation
Ensure:
•	Past dates are rejected.
•	Closed business days are rejected.
•	Trading hours are enforced.
•	Slot duration and alignment are enforced.
•	Maximum advance-booking limits follow business settings.
•	Fully booked slots cannot be reserved.
•	Capacity is checked immediately before order creation.
Preserve the existing database locking strategy used by SlotService::reserveCapacityLocked().
Test actual concurrent reservation attempts.
For example:
If a slot has one remaining space and two customers attempt to book it simultaneously, exactly one must succeed.
 
7. Phase E — Order lifecycle and staff operations
Priority: Critical
Review:
•	Order.php
•	OrderService.php
•	Staff order controllers.
•	Customer order controllers.
•	Payment and checkout services.
E1. Order state machine
Preserve and enforce the existing Order::ALLOWED_TRANSITIONS rules.
•	Every transition must be validated server-side.
•	Customers must not directly change order statuses.
•	Staff must only perform authorised transitions.
•	Illegal transitions must return an appropriate error.
•	Payment state and fulfilment state must remain consistent.
•	History and state changes must be recorded atomically.
Do not allow a request to bypass the state machine by directly updating a database field.
E2. Staff order adjustments
When staff modify an order:
•	Revalidate products and options.
•	Recalculate prices.
•	Revalidate quantities.
•	Recalculate discounts and totals.
•	Record who performed the adjustment.
•	Record what changed.
•	Require customer confirmation for material price or order changes before charging.
Ensure staff cannot modify orders outside their authorised scope.
E3. Cancellation
Cancellation must account for the current order and payment state.
•	Prevent invalid cancellation of completed orders.
•	Do not silently treat a paid order as unpaid.
•	Record the actor, reason, timestamp, and previous/new state.
•	Reconcile refunds and loyalty changes where applicable.
E4. Duplicate order submissions
Implement idempotency for order creation where not already supported.
Use an idempotency key associated with:
•	Authenticated customer.
•	Operation.
•	Request fingerprint.
•	Stored result.
A retry with the same key and same request should return the original result instead of creating another order.
A request with the same key but a different payload must be rejected.
Do not accidentally prevent legitimate separate orders with distinct idempotency keys.
 
8. Phase F — Payment security
Priority: Critical
Review:
•	PaymentService.php
•	PayFast integration.
•	Payment controllers.
•	ITN/webhook handlers.
•	Payment-related database migrations.
F1. Payment verification
Ensure:
•	Browser redirects are never treated as proof of successful payment.
•	ITN verification follows the provider's required verification procedure.
•	Merchant identity is verified.
•	Amounts are compared against the authoritative order amount.
•	Currency is checked where supported by the provider contract.
•	Transaction references are validated.
•	Only valid payment state transitions are accepted.
F2. Webhook idempotency
Duplicate payment notifications must not:
•	Charge the customer again.
•	Create duplicate payment records.
•	Award loyalty points twice.
•	Trigger duplicate business state transitions.
•	Send duplicate customer notifications unnecessarily.
Persist unique provider transaction/event references where appropriate.
Ensure database uniqueness constraints enforce the intended behaviour.
F3. Unknown payment outcomes
Preserve the existing approach for unknown charge outcomes.
A timeout must not automatically mean that a payment failed.
•	Avoid blindly retrying uncertain payment operations.
•	Reconcile against provider status.
•	Maintain a pending/reconciliation state where appropriate.
•	Ensure customers and staff can distinguish pending from confirmed payment.
F4. Payment secrets
Ensure:
•	Payment tokens are never returned to the frontend.
•	Tokens are not written to logs.
•	Encryption keys are not stored alongside encrypted values.
•	Credentials are only accessible through server-side environment configuration.
•	Sensitive values are excluded from error messages.
•	Decryption is restricted to the operations that genuinely require it.
F5. Refund protection
If refund functionality exists, strengthen it. If it does not exist, do not invent a new customer-facing refund workflow.
Any implemented refund operation must:
•	Require appropriate staff/admin authorisation.
•	Have a valid reason.
•	Reference the original payment.
•	Never exceed the captured amount.
•	Be idempotent.
•	Record provider reconciliation.
•	Update related loyalty and order records according to business rules.
•	Produce an audit record.
 
9. Phase G — Authentication and authorisation
Priority: Critical
Review:
•	AuthService.php
•	Authentication middleware.
•	Role middleware.
•	Session management.
•	Customer, staff, and admin controllers.
G1. Role-based access control
Enforce permissions on the backend, not merely through frontend navigation or hidden buttons.
Roles must remain distinct:
•	Customer.
•	Staff.
•	Admin.
Ensure sensitive administrative operations require the appropriate role.
Never trust a role supplied by the client.
G2. Object-level authorisation
Test that customers cannot access another customer's:
•	Cart.
•	Orders.
•	Addresses.
•	Payment records.
•	Profile.
•	Loyalty balance.
•	Private information.
Test this by changing IDs in API requests.
Return an appropriate unauthorised or not-found response without disclosing another customer's data.
G3. Sessions
Preserve existing session lifetime policies while ensuring:
•	Session IDs are regenerated after authentication.
•	Cookies use appropriate Secure, HttpOnly, and SameSite settings.
•	Sessions are invalidated on logout.
•	Sensitive account changes invalidate or refresh relevant sessions.
•	Idle and absolute expiration are enforced server-side.
•	Sensitive operations require appropriate re-authentication where justified.
G4. Login abuse protection
Review existing rate limiting.
Protect against:
•	Brute-force attacks.
•	Credential stuffing.
•	Excessive verification attempts.
•	Password-reset abuse.
•	Verification-token guessing.
Use appropriate IP/account-based limits and avoid permanent account lockouts that could be abused to deny service.
G5. CSRF and CORS
Ensure:
•	State-changing session-authenticated requests have CSRF protection.
•	CORS only allows intended origins.
•	Credentialed requests do not use wildcard origins.
•	CORS is not treated as an authentication mechanism.
Preserve existing working CSRF and CORS integration.
 
10. Phase H — Upload security and object storage
Priority: High
Review product image uploads and S3-compatible storage integration.
Ensure:
•	Upload size limits are enforced.
•	MIME type is determined using file contents, not just extensions.
•	Only approved image formats are accepted.
•	Files are actually decodable images.
•	Image dimensions and pixel counts are limited.
•	Images are re-encoded where practical.
•	EXIF metadata is removed where appropriate.
•	Random object keys are generated server-side.
•	Client-supplied object keys are never trusted.
•	Uploaded files cannot be executed as server-side code.
•	Storage credentials are not exposed to the browser.
Handle failed uploads and orphaned storage objects safely.
Do not break existing product image URLs or customer image rendering.
 
11. Phase I — RAG assistant security and reliability
Priority: High
Review:
•	RagService/README.md
•	RAG retrieval implementation.
•	RagService/tests/test_retrieval.py
•	PHP RAG proxy and related API endpoints.
The RAG assistant is an informational feature. It must not become an alternative transactional interface.
I1. Grounded answers
•	Answer using available, relevant evidence.
•	Do not invent FoodByK policies, prices, operating hours, delivery availability, or product details.
•	If evidence is partial, explain what is known and what remains uncertain.
•	If sources conflict, avoid presenting an unsupported definitive answer.
•	If no reliable evidence exists, use a clear fallback.
I2. Prompt injection
Treat retrieved documents, webpages, and user-provided content as untrusted data.
Instructions found inside retrieved documents must never override system or application instructions.
Do not expose:
•	Secrets.
•	Environment variables.
•	API keys.
•	Passwords.
•	Payment tokens.
•	Internal system prompts.
•	Private customer information.
I3. Transactional boundaries
The RAG assistant must not independently:
•	Change order status.
•	Process payments.
•	Issue refunds.
•	Modify carts.
•	Change customer addresses.
•	Alter loyalty balances.
•	Access private customer records.
Authenticated transactional questions must be directed to the appropriate secure application workflow.
I4. Retrieval and performance
Preserve the existing PHP proxy boundary and rate limiting.
Ensure:
•	Question length limits are enforced.
•	Retrieved context is bounded.
•	Completion length is bounded.
•	Requests have sensible timeouts.
•	Excessive requests are rate-limited.
•	Errors return safe fallback messages.
•	Live product prices and availability come from authoritative application data rather than stale vector-index content.
Add evaluation tests for representative FoodByK questions, including paraphrased questions, incomplete evidence, conflicting sources, and malicious retrieved instructions.
 
12. Phase J — Notifications and external integrations
Priority: High
Review Resend, Twilio, PayFast and other external integrations.
Ensure:
•	Provider failures do not corrupt committed orders.
•	Timeouts are handled safely.
•	Retries are bounded.
•	Sensitive credentials are never logged.
•	Customer-provided content is safely escaped in messages.
•	Notifications are triggered by committed business events, not merely frontend button clicks.
•	Duplicate callbacks do not unnecessarily trigger duplicate notifications.
Where appropriate, use an outbox pattern:
1.	Commit the business state and notification event in the same database transaction.
2.	Process the notification separately.
3.	Track delivery attempts and outcomes.
4.	Retry safely without duplicating the business operation.
Do not introduce a large queueing framework if the existing architecture supports a simpler implementation.
 
13. Phase K — Database integrity and API resilience
Priority: High
Review existing migrations and database constraints.
Add missing constraints where they reinforce actual business rules.
Potential constraints include:
•	Positive order quantities.
•	Non-negative prices.
•	Non-negative option prices.
•	Non-negative delivery fees.
•	Non-negative discounts.
•	Non-negative loyalty balances.
•	Valid promotion percentages.
•	Positive slot capacity.
•	Unique payment transaction references.
•	Unique processed provider event references.
Do not blindly add constraints without checking existing data and application behaviour.
For each migration:
•	Ensure compatibility with the current schema.
•	Avoid destructive changes.
•	Consider existing production data.
•	Make rollback behaviour clear.
•	Test migration execution against the test database.
API response handling
Use appropriate HTTP status codes consistently:
•	400 — malformed request.
•	401 — unauthenticated.
•	403 — authenticated but unauthorised.
•	404 — resource not found.
•	409 — state conflict or duplicate operation.
•	413 — request too large.
•	422 — valid request format but invalid data.
•	429 — rate limit exceeded.
•	500 — internal server error.
•	502/503 — upstream service unavailable.
Do not expose stack traces, SQL errors, credentials, or internal implementation details to customers.
Resource limits
Enforce sensible limits on:
•	Request body size.
•	Search queries.
•	Pagination.
•	Upload size.
•	RAG questions.
•	Array lengths.
•	Nested JSON depth.
•	Export requests.
 
14. Phase L — Frontend validation and user feedback
Priority: High
The frontend should help users submit valid information, but the backend remains authoritative.
For customer-facing forms and controls:
•	Use appropriate input types.
•	Apply min, max, step, and maxlength where relevant.
•	Display clear inline validation messages.
•	Preserve valid user input after failed requests.
•	Prevent repeated submissions while requests are in progress.
•	Restore the previous valid value after a rejected update.
•	Do not silently reset user input.
•	Display meaningful loading, empty, success, and error states.
For cart quantity inputs specifically:
•	Use whole-number controls.
•	Enforce the visible limits.
•	Reject invalid values.
•	Restore the previous valid quantity when the server rejects an update.
•	Display a useful explanation.
For asynchronous operations, never assume that a request succeeded just because the frontend initiated it.
When an outcome is uncertain, refresh authoritative server state before allowing further mutations.
Do not unnecessarily change the visual design of customer pages.
 
15. Phase M — Logging, privacy and audit trails
Priority: High
Review application logging and administrative audit functionality.
Never log:
•	Passwords.
•	Password-reset tokens.
•	Verification tokens.
•	Payment tokens.
•	API keys.
•	Database credentials.
•	Session IDs.
•	Full payment card data.
•	Sensitive authentication request bodies.
Log useful operational information without exposing secrets.
For sensitive administrative and staff actions, record:
•	Actor ID.
•	Actor role.
•	Action.
•	Resource type.
•	Resource ID.
•	Previous state where appropriate.
•	New state where appropriate.
•	Timestamp.
•	Request/correlation ID.
Protect audit records from unauthorised modification or deletion.
Review data retention for abandoned carts, authentication tokens, logs, customer records, orders, and payment information. Preserve records where legal or operational requirements demand it.
 
16. Phase N — Caching and deployment resilience
Priority: Medium
Review frontend, API, catalogue, and RAG caching.
Ensure:
•	Public product images can be cached appropriately.
•	Catalogue cache is invalidated after relevant product changes.
•	Promotions and business settings do not remain stale after updates.
•	Customer carts, profiles, orders, and payment information are never served through inappropriate shared caches.
•	Financial decisions never rely on cached values.
•	RAG catalogue information has an appropriate freshness policy.
Review deployment configuration.
Ensure local, test, staging, and production environments use isolated configuration and credentials.
Add or improve smoke checks for:
•	API availability.
•	Database connectivity.
•	Migration state.
•	Storage connectivity.
•	Email configuration.
•	Payment configuration.
•	RAG service availability.
Do not expose environment details in public API responses.
 
17. Required automated regression tests
Add tests that prove the following behaviours.
Cart tests
•	Valid positive integer quantity succeeds.
•	Zero quantity update is rejected.
•	Negative quantity is rejected.
•	Decimal quantity is rejected.
•	Malformed strings are rejected.
•	Extremely large quantities are rejected.
•	Configured quantity limits are enforced.
•	A customer cannot modify another customer's cart.
•	Explicit deletion works.
•	Failed updates do not delete cart items.
•	Concurrent cart updates cannot bypass limits.
Pricing and checkout tests
•	Client-supplied prices are ignored.
•	Client-supplied totals are ignored.
•	Unavailable products are rejected.
•	Invalid product options are rejected.
•	Invalid option/product relationships are rejected.
•	Checkout recalculates all financial values.
•	Promotion calculations are correct.
•	BOGO calculations are correct.
•	Negative totals cannot be produced.
•	Price changes require customer review where applicable.
Scheduling tests
•	Past slots are rejected.
•	Closed days are rejected.
•	Invalid time alignment is rejected.
•	Full slots are rejected.
•	Concurrent capacity reservations cannot oversell.
Order tests
•	Invalid state transitions are rejected.
•	Customers cannot change their order status.
•	Staff permissions are enforced.
•	Staff adjustments recalculate financial values.
•	Duplicate order submissions are idempotent.
•	Different legitimate orders remain possible.
•	Cancellation respects payment state.
Payment tests
•	Invalid ITN verification is rejected.
•	Incorrect merchant details are rejected.
•	Incorrect payment amounts are rejected.
•	Duplicate ITN events are idempotent.
•	Duplicate payment records cannot be created.
•	Loyalty points cannot be awarded twice.
•	Browser redirects cannot independently mark orders paid.
•	Unknown payment outcomes are not blindly retried.
Authentication tests
•	Customers cannot access another customer's data.
•	Customers cannot assign themselves staff/admin roles.
•	Unauthorised endpoints are protected.
•	Session expiration works.
•	CSRF protection works.
•	Rate limiting works.
•	Sensitive tokens do not appear in responses or logs.
Upload and RAG tests
•	Unsupported uploads are rejected.
•	Oversized uploads are rejected.
•	Invalid image files are rejected.
•	Malicious retrieved instructions cannot override RAG behaviour.
•	Unsupported answers are not fabricated.
•	RAG cannot access private transactional data.
•	Rate and resource limits are enforced.
 
18. Implementation order and scope control
Implement in this order:
First — Critical integrity
1.	Strict cart quantity validation.
2.	Explicit cart deletion.
3.	Cart ownership and quantity limits.
4.	Server-authoritative pricing.
5.	Checkout recalculation.
6.	Delivery and slot validation.
7.	Order state machine and authorisation.
8.	Order creation idempotency.
9.	Payment verification and webhook idempotency.
10.	Authentication and object-level authorisation.
Second — High-priority protection
11.	Promotion and loyalty integrity.
12.	Staff adjustment audit.
13.	Upload hardening.
14.	RAG security and evaluation.
15.	Notification reliability.
16.	API and database integrity.
17.	Privacy and logging.
Third — Operational resilience
18.	Caching.
19.	Deployment smoke tests.
20.	Monitoring and recovery improvements.
Do not attempt to implement everything in one massive, unreviewed change.
Keep each phase independently understandable and testable.
If a requirement depends on an external provider, an unavailable credential, a missing environment, or an unresolved business rule, implement what can safely be implemented and document the limitation instead of inventing behaviour.
 
19. Definition of done
The task is complete only when:
•	Existing functionality remains operational.
•	Customer-facing pages retain their established appearance and workflows.
•	Critical guardrails are enforced server-side.
•	Database constraints support important business rules.
•	Relevant automated regression tests exist.
•	Existing tests still pass.
•	No secrets have been introduced into source control.
•	No critical security regressions have been introduced.
•	Database migrations are documented and tested.
•	Changes are clearly reported.
Run the available test suite, including relevant backend tests, frontend tests, integration tests, and the existing load-testing checks where the environment supports them.
Do not report tests as passed if they were not actually executed.
Do not hide failures or weaken assertions to obtain a green result.
20. Final report format
At completion, provide:
A. Summary
What was implemented and the overall result.
B. Guardrail status
A table containing:
Guardrail	Initial status	Final status	Tests
Cart validation			
Pricing integrity			
Checkout validation			
Promotions and loyalty			
Delivery and scheduling			
Order lifecycle			
Payment security			
Authentication and authorisation			
Upload security			
RAG security			
Notifications			
Database integrity			
Logging and privacy			
C. Files changed
List the important files and explain why they changed.
D. Database changes
List migrations and their impact.
E. Tests
List the tests executed, their results, and any failures.
F. Outstanding risks
Identify anything that still requires manual verification, external configuration, business decisions, or additional work.
G. Recommended next steps
Prioritise any remaining issues by severity.
Final instruction: Work with the existing FoodByK architecture. Make the smallest safe changes that achieve the required protections. Preserve working functionality. Validate every important assumption against the actual code. Never sacrifice correctness, security, or data integrity just to complete the checklist faster.

