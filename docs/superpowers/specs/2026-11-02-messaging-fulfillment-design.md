# Messaging, fulfillment and authentication foundation

User request: add Messenger-style customer/station communication, pickup or delivery selection, and local Supabase authentication/database migrations for later connection. Proceed within this explicit design direction using the established cyan Tailwind UI; no live project connection or migration application is authorized or needed.

## Messaging
Two-pane inbox at /customer/messages and /station-admin/messages. Customer Maria sees only her station threads; Guintas Station Admin sees only its customer threads. Modern cyan outgoing bubbles, white incoming bubbles, avatars, searchable thread list, unread indicators, context links, dates, composer, Enter-to-send/Shift+Enter newline, emoji choices, mobile list/chat navigation. Local-only sends use textContent, persist per conversation and synchronize between role tabs through storage events. No real online/typing/delivery claims. Clear local-preview note. Riders and Super Admin receive no message routes or message data.

## Fulfillment
Delivery is default. Checkout offers Delivery or Pickup with icon radio cards. Pickup shows station address/hours, disables delivery-address requirements, uses cash-on-pickup copy, and shares scheduling controls. Existing product prices stay PHP12/PHP20/PHP20. Orders and tracking display the selected method. Station Admin receives a pickup queue with Ready for pickup and Collected states; pickup orders never appear in dispatch.

## Database foundation
Supabase Auth owns credentials, Google identities and sessions. Local migrations add protected profiles, authoritative roles/memberships, public station metadata, addresses/products and minimal orders/items with fulfillment constraints, conversations/messages/receipts and strict participant RLS. Only trusted provisioning can promote roles. Never authorize using editable user_metadata. Super Admin cannot read messages/orders/financial records. No custom password or token tables. SQL files are prepared locally with CLI-generated filenames, not pushed.

## Validation
Existing UI suites plus messaging/fulfillment DOM regressions and PHP lint. Execute migrations and RLS tests using an isolated PostgreSQL-compatible PGlite database with a documented Supabase Auth harness; do not claim hosted Auth/Realtime integration tested. Browser visual QA depends on available host. Document future Google OAuth/Auth configuration and migration commands.

