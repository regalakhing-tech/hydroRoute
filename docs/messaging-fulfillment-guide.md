# Messaging, pickup and delivery

The app now includes customer and station inboxes styled around the existing cyan theme, with conversation lists, readable bubbles, order context, unread markers, emoji choices and a keyboard-friendly composer.

## Open the previews

- Customer messages: http://127.0.0.1:8001/customer/messages
- Station messages: http://127.0.0.1:8001/station-admin/messages
- Choose pickup or delivery: http://127.0.0.1:8001/customer/checkout?station=guintas
- Station pickup queue: http://127.0.0.1:8001/station-admin/pickups

Run the PHP server from this project if it is not already running:

```powershell
php -S 127.0.0.1:8001 -t public public/router.php
```

Station details and customer tracking have Message station buttons. Station orders and customer records have Message customer links. Maria's customer preview can speak with her three station threads. Guintas Station Admin sees its six sample customer threads. Riders and Super Admin have no inbox routes or conversation fixtures.

Messages stay on this device. Opening both customer and station previews in the same browser lets you try replies across tabs. They do not contact a real station or customer. Enter sends, Shift+Enter adds a line, and the emoji button inserts a choice. Read markers say Seen in preview. Browser-storage failure keeps new messages in memory for the session and displays that limit. Message bodies are rendered as text.

## Pickup and delivery

Checkout defaults to delivery. Choose Pickup to display the station location and hours, disable delivery-address requirements, choose a pickup time and use cash-on-pickup copy. Product prices stay PHP12 for a bottle and PHP20 for either refill container.

Pickup and delivery drafts are separate and preserve the selected product quantities. Pickup drafts omit disabled delivery-address fields. Pickup tracking uses collection stages and does not show an assigned rider. Station pickup actions move a sample order from preparation to ready, then picked up, with confirmation. Pickup orders are excluded from delivery dispatch.

## Local database files

Migration: `supabase/migrations/20261102144011_access_fulfillment_messaging.sql`.
It prepares Supabase Auth-linked profiles, protected roles and memberships, approved station/catalog visibility, addresses, fulfillment orders/items, conversations, messages and read receipts. Passwords, identity providers and sessions remain Supabase Auth's responsibility.

No Supabase project was linked or changed. The UI still uses sample data. The migration has been executed only in an isolated PGlite PostgreSQL test database with test-only Auth/role stand-ins. Actual Supabase Auth signup, Google OAuth, Data API, Realtime and deployment still need integration and testing later. See [Supabase setup](../supabase/README.md) and [database design](database-design.md).

## Verification commands

With PHP running:

```powershell
php tests/ui-smoke.php http://127.0.0.1:8001
node tests/ui-dom.mjs http://127.0.0.1:8001
node tests/messages-dom.mjs http://127.0.0.1:8001
node tests/messages-regressions.mjs http://127.0.0.1:8001
node tests/fulfillment-dom.mjs http://127.0.0.1:8001
node supabase/tests/security.mjs
```

Browser automation was unavailable, so actual desktop/mobile screenshots still need manual visual review. DOM tests exercise behavior and structure rather than browser layout.

