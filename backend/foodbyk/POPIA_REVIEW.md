# POPIA implementation review

Review date: 13 October 2026. This is an engineering gap assessment, not a legal opinion or a certification of compliance.

## Main findings and code changes

| Area | Finding | Change in this implementation |
|---|---|---|
| Transparency and collection notice (POPIA §§ 13, 18) | The customer flow had no linked notice explaining purposes, recipients, rights, and retention. | Added a versioned Privacy Notice and Terms of Service; registration requires terms acceptance and privacy notice acknowledgment. The backend stores each policy type/version/time. |
| Data subject rights ( §§ 23–24) | Customers had no self-service erasure control. Orders have foreign keys to users and addresses, so hard deletion would break financial/order history. | Added authenticated, password-confirmed account deletion. It blocks while orders are unresolved, clears profile/address/contact data, cart/reset tokens and PayFast charge tokens, pseudonymizes retained order/payment links, and clears free-text order notes. |
| Purpose/retention ( §14) | No visible explanation of retained historical transactions or account erasure behavior. | Notice and UI explain that strictly retained transaction records may remain pseudonymized. An actual retention schedule and backup expiry policy remain required. |
| Security safeguards ( §§19–22) | Full customer details and a session credential were persisted in browser local storage. | Session token is now limited to session storage; the persistent user cache contains only ID and role. Existing cookie settings are HttpOnly/Secure in production. |
| Cookie transparency and choice | No cookie/storage notice or control existed. | Added essential-only and accept-all preference UI, versioned local choice, and a cookie policy. No optional analytics/advertising scripts are currently configured; “accept all” therefore does not activate any optional tracker. |
| Policy enforcement | Existing customer accounts could continue without seeing updated documents. | Customer ordering, address, and order APIs return HTTP 428 until current policy versions are accepted; profile, policy acceptance, and account deletion remain available. |
| Payment data | PayFast token is a bearer capability and must not survive an account erasure request. | Deletion clears every linked gateway token. Existing transaction status/reference data are preserved for reconciliation, subject to a documented retention period. |

## Legal provisions considered

The design follows POPIA’s purpose specification and collection notice requirements (§§ 13, 18), minimal retention and destruction/de-identification (§ 14), security safeguards and operator responsibilities (§§ 19–22), access/correction/deletion rights (§§ 23–24), direct marketing consent rules (§ 69), and cross-border transfer conditions (§ 72). Acknowledging a privacy notice is not treated as consent for every processing activity; terms acceptance, notice acknowledgment, and optional cookie choice are separate controls.

## Required before production sign-off

1. Replace the visible legal-document placeholders with the responsible party’s full legal name, physical/service address, working privacy/support contact, and registered Information Officer/Deputy details. Confirm registration with the Information Regulator.
2. Have South African privacy/consumer counsel review these draft terms/notices, especially order cancellation, pricing, refunds, legal bases, and retention periods.
3. Inventory the actual providers and data flows (hosting, payment, transactional email, WhatsApp/SMS, geocoding, storage, FAQ model). Confirm operator contracts, access controls, subprocessors, hosting locations, and POPIA §72 transfer safeguards where information leaves South Africa.
4. Define and implement a record-by-record retention schedule and backup deletion/rotation process. The account deletion route preserves historical order/payment records but this code does not decide a statutory retention period.
5. Complete an incident-response plan, including operator escalation and notification workflows under §22; verify production logging does not contain credentials, address payloads, or payment tokens.
6. Ensure every direct marketing channel has a valid §69 consent/exception basis and working opt-out. Transactional order updates must remain limited to service communications.
7. Review any new analytics, advertising, chat, or tracking code before deployment. The current cookie choice is a preference record, not a CMP capable of gating scripts that are not presently included.

## Migration and operational notes

Fresh databases receive `users.deleted_at` and `policy_acceptances` from `database/migration.sql`. Existing databases must apply `database/popia_compliance.sql` once, after a backup and schema check, before deploying the new backend. Update `PolicyService` versions whenever the customer-facing terms/privacy notice materially changes; users will then be prompted again. Deploy backend migration and API before publishing frontend registration that sends the new acceptance fields.

Account deletion is intentionally not a universal hard delete: active orders are blocked; historical orders/payments are kept with customer identity fields removed because the platform needs those records for payment reconciliation, accounting, and disputes. Food by K must define the lawful retention periods and purge records when those purposes expire.
