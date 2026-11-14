# Messaging and fulfillment implementation contract

Existing shared helpers/style: docs/ui-contract.md. Use App\\UI\\UI as U, Tailwind colors ink/water/foam/mist/line/muted, Manrope headings and Source Sans 3 body. Preserve approved product prices.

## Root ownership
app/UI/Pages.php, app/UI/UI.php, app/Views/layouts/app.php, app/Views/partials/head.php, app/Controllers/UIController.php if needed, public/index.php, public/assets/js/app.js, resources/css/app.css, package.json and lock, tests/ui-smoke.php and existing tests/ui-dom.mjs integration.
Root adds message/send/emoji/back icons and routes:
- /customer/messages => customer/messages.php
- /station-admin/messages => station-admin/messages.php
- /station-admin/pickups => station-admin/pickups.php
Customer and station nav each get Messages; station nav also gets Pickups.
Root loads /assets/js/messages.js and /assets/js/fulfillment.js deferred AFTER app.js and allowlists both. Root hides ordinary layout footer on messages screens.
Root generic serializer skips disabled fields. Root generic search filter will support select[data-filter-key] matching row.dataset[key] exactly for fulfillment combined with existing status.

## Messaging worker ownership
Create app/UI/MessagingData.php, app/Views/shared/messages.php, app/Views/customer/messages.php, app/Views/station-admin/messages.php, public/assets/js/messages.js, tests/messages-dom.mjs, docs/messaging-ui-report.md. No other files.
MessagingData::forRole(string $role): array must reject roles other than customer/station-admin and return only that role's conversations and viewer identity.
Shared fixture IDs: guintas-maria, guintas-luis, guintas-ana, poblacion-maria, riverside-maria. Customer viewer id customer:maria, station viewer id station:guintas. Each role receives only its authorized fixtures. Optional context order HMT104 delivery, HMT105 pickup.
Use data-chat-root and application/json script seed serialized with JSON_HEX_TAG/AMP/APOS/QUOT. Render all message bodies with textContent. Versioned localStorage thread keys shared between previews, no false real send/read claims. Add Event storage listener. Safe handling of corrupt storage, blank or overlong messages and invalid thread query. Content size max2000chars.
At /customer/messages?thread=guintas-maria or ?station=guintas select relevant valid thread. At /station-admin/messages?thread=guintas-maria or ?customer=maria select relevant valid thread.
Responsive two-pane inbox, headings exactly one h1, distinct accessible field labels, local preview disclosure. Native usable mobile back/list control. Use ordinary Tailwind static class strings so compiler scans JS.
Root will add entry links from customer station/tracking and station customer/orders after views land.
DOM tests can reuse JSDOM pattern from tests/ui-dom.mjs, loading both app.js then messages.js with dialog/scroll/CSS.escape stubs. Do not modify existing tests/ui-dom.mjs.

## Fulfillment worker ownership
app/UI/Fulfillment.php (new), app/UI/CustomerData.php, app/UI/StationData.php, app/Views/customer/checkout.php, app/Views/customer/orders.php, app/Views/customer/tracking.php, app/Views/station-admin/orders.php, app/Views/station-admin/deliveries.php, app/Views/station-admin/pickups.php (new), public/assets/js/fulfillment.js, tests/fulfillment-dom.mjs and docs/fulfillment-ui-report.md. Do not edit root or messaging files.
Add fulfillment radio cards name=fulfillment value=delivery/pickup in checkout, default delivery or validated query. Scope saved draft key by mode if needed; preserve existing canonical basket isolation and old tests unless behavior intentionally refined. data-fulfillment-root/form attributes chosen by you; implement fully within own module.
Delivery address panel has fields required/enabled only in delivery; pickup location panel only in pickup. Pickup has Cash on pickup, no delivery address or driver assignment, scheduled pickup labels, no extra fees. Generic app.js handles legacy name=delivery ASAP/scheduled; coordinate within feature module on load and changes.
Customer order fixtures add fulfillment values. Choose HMT098 as a collected pickup sample; other customer orders retain delivery. Station tuple fixture index7 stores fulfillment delivery/pickup; HMT105 and HMT109 pickup, other orders delivery. Existing priority scores unchanged.
Use specific pickup timeline, labels and actions, no rider/contact/dispatch for pickup. Station pickup queue filters/search and confirm actions use shared data hooks. Exclude pickups from delivery ready table and selected dispatch lists/load.
Tests: switching modes hides/disables address; FormData for pickup has no address, correct payment/location copy; local restore; invalid array selectors safe; pickedup tracking no rider/outfordelivery; pickup order never in dispatch. Run existing UI DOM tests to detect regressions, but leave their file changes for root unless coordinated.

## Database worker ownership
supabase/migrations/*.sql, supabase/tests/*, supabase/README.md, docs/database-migration-report.md, docs/database-design.md. Root installs supabase CLI3.0.0 and @electric-sql/pglite0.5.14; do not install dependencies or edit package files concurrently.
First inspect .bin/supabase.cmd --help and migration new --help. Generate actual migration filenames with CLI before authoring; project currently has empty migration directory and no config. Do not connect/link/push/start cloud or local Supabase/Docker. Need only local files.
Create auth roles/profiles/memberships, minimum station/address/product/order foundation for fulfillment, conversations/messages/read receipts. Supabase Auth owns passwords, OAuth, sessions. Ignore role claims in user_metadata. Trusted role provisioning only. RLS on every exposed table, explicit grants, least privilege, immutable ownership, indexes on FKs and relevant role/timeline filters, exact station/customer isolation. Super Admin forbidden messages/orders/order counts/payments even if accidentally given station membership. Readers/senders must be correct customer participant or active station admin; rider not a chat party.
Use integer centavos for product snapshots and validated mixed item totals, ensure orders/products/items station consistency, delivery requires owned address, pickup no address/driver, only valid method/status combinations. No authenticated ability to forge roles, sender/timestamp, membership, order price/status; use constrained column grants or narrow verified RPC where necessary. Keep scope to future foundation, not a whole dispatch backend.
Private SECURITY DEFINER helpers may be necessary to avoid policy recursion: fixed empty search_path, explicit auth.uid()/role checks, revoked PUBLIC execution, only minimal grants, never arbitrary user IDs. Trigger-only bootstrap checks trigger context when auth.uid unavailable. Signup creates customer access only, backfills existing users safely.
Test with PGlite.create() in memory, test-only auth.users/auth.uid() stubs and anon/authenticated roles; execute actual migrations unchanged and exercise real RLS with SET ROLE. No production/demo Auth users in migrations. Test impersonation/cross-tenant/role escalation/anonymous/rider/super-admin denials and expected participant actions, fulfillment constraints and price protection. Document this is not hosted Supabase integration.

