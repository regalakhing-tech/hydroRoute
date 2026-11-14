# Database migration report

The locally generated migration adds Auth-backed profiles, private trusted roles and station memberships, public stations and product catalog, owned addresses, fulfillment orders and price snapshots, and participant-scoped conversations, messages and receipts. Every exposed table has RLS and explicit client grants. Super Admin is excluded from customer order and messaging data regardless of station membership; riders cannot access chat. See [database design](database-design.md) for exact scope and integration requirements.

`node supabase/tests/security.mjs` executes the migration unchanged in PGlite 0.5.14 with test-only Auth schema/role stubs. Tests cover signup/backfill and untrusted role metadata, customer and station-admin access, cross-customer/station isolation, anonymous and rider denial, Super Admin with accidental membership, sender/time/ownership/price/status forgery, receipt ownership and server timestamp, message length, address ownership, pickup restrictions, and station consistency of item references. Test users and role provisioning are in-memory fixtures only.

No Supabase project has been linked or changed; hosted Auth, Realtime, Data API exposure and production migration/advisors are not tested. Trusted backend work is still required for atomic order creation, current product price validation and snapshots, basket totals and fees, status transitions, role provisioning, conversation creation, delivery assignment, and payments. The migration intentionally grants no client order/item write privileges in advance of that work.


## Resolution verification

The coordinating implementation resolved the approval and authorization-test findings. New stations have registration_status pending; public station/product policies require approved and active. Registry and own-station metadata policies are separate from public directory access. Participants can read same-conversation receipts but only create/refresh their own.

The revised suite actually switches client database roles and checks both station/customer sides, active/inactive/revoked memberships, a Super Admin with customer and station-admin roles plus active membership and owned records, rider-owned threads, cross-conversation receipts, timestamp and ownership forgery, independent item/order/product foreign keys, order-linked conversation mismatches, and valid pickup. It also rejects whitespace-only or overlong messages.

Fresh root verification: 107 PostgreSQL/RLS assertions pass against the actual migration. This is coordinating implementation verification, not a second independent review or hosted Supabase integration test. No project was connected or changed.