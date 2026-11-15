# Food by K — public FAQ and service rules

Reviewed 15 November 2026 against the public Food by K website and the FoodByK
application code. The official customer site is `https://foodbyk.co.za/`.

## Important source and policy limits

The live site publishes the business facts and customer-facing rules below. Its
Terms of Service and Privacy Notice currently show an effective date of 13
October 2026, but both pages also contain explicit “complete before
publication” notices and say that required legal/contact details are missing.
Treat those pages as incomplete drafts, not as definitive statements of every
business policy. Food by K should complete and review them before relying on them
as final legal notices. South African consumer rights still apply.

Application mechanics below were checked against the repository on the review
date. They explain how this version of the app is written; they do not reveal
the live Railway/database settings. Checkout and the current offer page are the
source of truth for account-specific or changing availability, fees, prices,
and totals. Do not promise a result for a particular order.

## About Food by K

What is Food by K? Food by K describes itself as “Food. Love. Soul.” and invites
customers to explore its menu, meal specials, and favourites. The business is
based in Lenasia South, Gauteng. Its public site describes its food as freshly
made; that general description does not confirm the ingredients or preparation
of a particular menu item.

What is the address? The public site lists 8513 Goshawk Street, Lenasia South,
Gauteng, South Africa.

What are the opening hours? The public homepage lists Monday to Saturday,
11:00–18:00 (11 AM–6 PM). It does not publish Sunday hours. Public app code
does not offer Sunday order slots. Hours or order slots can change; check the
current site and checkout for holiday exceptions and live availability.

How can I contact Food by K? The homepage lists 081 447 8365 for calls and
WhatsApp: https://wa.me/27814478365. Food by K also links Instagram at
https://www.instagram.com/food_by_k_/, TikTok at
https://www.tiktok.com/@foodbyk1, and Facebook at
https://web.facebook.com/p/Food_By_k_-100084958604392/.

## Menu, cart, and prices

Where can I see the menu and current prices? Use the live menu at
https://foodbyk.co.za/pages/customer/menu. Product names, descriptions, prices,
and availability can change. The app rechecks that cart products remain active
and available and uses current catalogue prices for the checkout preview; it
saves an order price snapshot at submission. Do not quote stale third-party
prices.

Is there a maximum number of items in a cart? No public maximum was found. The
current cart service has no overall cart-line or total-quantity cap. Adding the
same product again increases that product's quantity on its existing cart line.
The customer cart code accepts positive quantities, and zero or a negative
quantity removes a line when updating it. A separate staff order-adjustment
screen limits a product line to 99; that is not evidence of a 99-item customer
cart limit. Never invent a maximum spend or basket size. Checkout may still fail
if an item becomes unavailable or another order constraint applies.

Can I ask about ingredients, allergens, or dietary requirements? The live menu
may not list every ingredient or cross-contamination detail. Do not claim an
item is vegetarian, vegan, halal, gluten-free, allergy-safe, or suitable for a
medical/dietary restriction unless the current menu explicitly says so. For
unanswered ingredient or allergen questions, contact Food by K before ordering.

## Promotions and discounts

Where are current promotions listed? Use
https://foodbyk.co.za/pages/customer/promotions. The public promotions page
says only one promotion code can be used per order. Offers apply to eligible
items and the discount is confirmed in the checkout total. The app validates
the code and its active date range at checkout; current offers and terms can
change.

What kinds of offers can the app calculate? The current app supports percentage
discounts, fixed-amount discounts, buy-one-get-one offers, and free delivery.
Offers can apply to selected menu products or the whole order. Percentage
discounts are capped at 100%; fixed discounts cannot exceed the eligible
subtotal. Buy-one-get-one discounts make the cheaper item free in each matching
pair of the eligible product. Free delivery waives the configured delivery fee
when the offer applies. These are supported offer types, not a promise that a
particular offer is currently available. Read the live offer card and check the
final checkout total.

Can I combine a promotion with loyalty points? The checkout code accepts one
promotion code and a loyalty redemption in the same preview. The promotion is
calculated first; loyalty points can then reduce the remaining order value,
including any delivery fee, down to zero. The live checkout total is
authoritative.

## Loyalty rewards

How do loyalty points work? The public Loyalty Rewards page states that
customers earn 1 point for every R100 actually paid after discounts, and 1 point
is worth R1 off a future checkout. The current app calculates points to two
decimal places and awards them only after successful payment. Pending, failed,
declined, or cancelled orders do not earn points.

How many points can I redeem? A customer can redeem up to their available
balance, but the discount cannot exceed the remaining order total after the
promotion. The app accepts point amounts to two decimal places. For example,
100 points can reduce an eligible future order by R100, but points cannot make a
negative total. Check the Loyalty Rewards page while signed in for your own
balance; this FAQ cannot look it up.

## Checkout, payment, collection, and delivery

How do I place an order? Add available menu items to your cart, choose
collection or delivery, select an available time, and review any promotion,
loyalty redemption, fees, and total shown at checkout. PayFast then securely
sets up the card used for the order. Staff review the request and may accept,
adjust, or decline it. A submitted request is not accepted until staff confirm
it.

Does placing an order mean it is accepted? No. The public Terms page describes
submission as a request for staff review. Staff may accept it, suggest an
adjustment, or decline it. Review any revised amount or time shown by the app.
Product availability and menu prices may change before staff review.

How does payment work? The checkout page says PayFast securely sets up the
customer's card after the order is placed, and the card is charged only after
staff accept the order. The app uses PayFast tokenization; Food by K's platform
does not receive the full card number. Payment confirmation can arrive
asynchronously, so a redirect or pending status is not proof that payment has
settled. Do not submit another order solely because confirmation is delayed;
check the account or contact staff.

What happens if I do not finish card setup? The current application expires an
abandoned card-setup attempt after 30 minutes and releases reserved loyalty
points. This is an implementation detail; if an order is stuck, contact Food by
K rather than relying on this FAQ to inspect it.

Can I pay cash? The app checkout described on the public site uses PayFast card
setup. A Gauteng Tourism article also reports that Food by K is card-only, but
that is a third-party report rather than a detailed official payment policy.
Confirm any in-person payment options with Food by K before relying on them.

How does collection work? Choose collection and one of the time windows offered
at checkout. Collection has no delivery fee in the current app logic, if
collection is enabled. Available times depend on business settings and remaining
slot capacity; the public site lists Monday–Saturday business hours, and the
app's slot logic excludes Sundays. A submitted time is a preference until staff
confirm the order.

How does delivery work? Choose delivery, select a saved address, and use the
eligibility and fee shown at checkout. The app requires a geocoded address and
checks distance from the configured business location. Its distance check is
straight-line distance, not road distance. Delivery radius, delivery fee, and
whether delivery is enabled are configurable, so this FAQ cannot give a
reliable current delivery boundary, fee, or time. If an address is outside the
delivery radius but within the configured collection radius, the app may offer
collection instead; farther addresses are outside the configured service area.

How are order time slots managed? Checkout offers future slots within the
configured trading hours. Slots disappear when their configured order capacity
is full. Slot duration, trading hours, and capacity are configurable; do not
promise a specific slot until checkout shows it and staff confirm it.

Can I cancel or get a refund? The Terms page currently says cancellation may be
requested while the platform allows it, but also flags its terms as an
incomplete draft. The app can release redeemed loyalty points for declined or
cancelled orders. Staff also have a PayFast refund process for eligible
successfully paid orders; that does not mean a refund is automatic or guaranteed.
Contact Food by K promptly for a cancellation, charge, or refund question. This
FAQ cannot cancel orders, verify payments, or promise refund eligibility or
timing.

## Privacy and support

What personal information does the app handle? The site's draft Privacy Notice
describes account/contact details, saved delivery addresses and coordinates,
cart and order information, payment status and a tokenized PayFast reference,
policy acknowledgements, cookie preferences, and security/session information.
It says the platform does not receive or store the full card number. The notice
also identifies service providers such as hosting, payment, messaging, and
geocoding providers, while explicitly saying provider details and safeguards
still need verification. Treat the notice as incomplete until Food by K
publishes a finalized version.

Can the FAQ assistant check my order, payment, address eligibility, or account?
No. The assistant answers public menu and general policy questions. It cannot
access customer accounts or decide order status, payment/refund outcomes,
delivery eligibility, or collection availability. Do not enter an address,
payment details, or other sensitive personal information into the assistant.
Use your account/checkout or contact Food by K for transaction-specific help.

## Sources and confidence

- Official homepage: https://foodbyk.co.za/src/
- Official menu: https://foodbyk.co.za/pages/customer/menu
- Official promotions: https://foodbyk.co.za/pages/customer/promotions
- Official loyalty page: https://foodbyk.co.za/pages/customer/loyalty
- Official checkout: https://foodbyk.co.za/pages/customer/checkout
- Official Terms: https://foodbyk.co.za/pages/legal/terms
- Official Privacy Notice: https://foodbyk.co.za/pages/legal/privacy
- Official support page: https://foodbyk.co.za/pages/customer/support
- Instagram: https://www.instagram.com/food_by_k_/
- TikTok: https://www.tiktok.com/@foodbyk1
- Facebook: https://web.facebook.com/p/Food_By_k_-100084958604392/
- Gauteng Tourism Hub Network, “Food_By_K_ Review,” updated 12 March 2026:
  https://www.gauteng.net/whats-on-g/food_by_k_-review-is-this-insta-famous-eatery-worth-the-hype/

Third-party listings can be outdated or conflict with official sources. The
Gauteng Tourism article is cited only for its corroboration of the address,
hours, and reported card-only service; use official pages and checkout for
current business facts and rules. App mechanics were checked against
`backend/foodbyk/services/CartService.php`, `CheckoutService.php`,
`PromotionService.php`, `LoyaltyService.php`, `DeliveryService.php`,
`SlotService.php`, `OrderService.php`, `PaymentService.php`, and the public
customer pages on 15 November 2026.
