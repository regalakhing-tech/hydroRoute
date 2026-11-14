# Local database security review

Reviewed 2 November 2026: `database-design.md`, `database-migration-report.md`, `supabase/README.md`, migration `20261102144011_access_fulfillment_messaging.sql`, and `supabase/tests/security.mjs`. This was a source review of the local foundation; no SQL/application changes, hosted connection, remote changes, or duplicate test run. The implementation worker reports the unchanged migration passes PGlite. That execution result is reported evidence, not a second independent run by this reviewer.

Verdict: no Critical issue found in the private-data access design. Two Important findings should be resolved before considering the planned authorization coverage complete.

## Important

1. **Approved-directory visibility is not represented.** Migration lines 15–18 create only `active`, defaulting to true; lines 139–140 expose active stations and their active products to anonymous callers. A trusted insertion that omits this flag immediately publishes the station, with no approval check. The primary project plan (`superpowers/plans/2026-11-01-hamtic-water-station-php-supabase.md`, permissions matrix near line 240 and registry operations near lines 449–453) distinguishes approved public directory records from pending registrations. The current database design documents active records, but does not define active as an approval decision or establish that this table exclusively receives approved registrations. Add a trusted approval state with a non-public default and require approval in public policies, or explicitly establish an approved-only directory table with a guarded publication path. Test an approved visible station alongside pending/suspended/otherwise unapproved hidden stations, including their products. This finding concerns the directory invariant, not a demand to implement the full registry backend now.

2. **The authorization suite does not establish several claimed boundaries.** `security.mjs` really does execute migration SQL and switch to non-owner `anon`/`authenticated` roles (lines 5–10 and 26), so it is a useful RLS suite. However:
   - The Super Admin fixture has membership but lacks `station_admin`, and owns no order or conversation (lines 18–25). It would remain denied even if the explicit Super Admin checks were removed. Give it both roles plus active membership, and also its own customer records; assert zero orders/items/addresses/conversations/messages/receipts and denied sends/receipt writes.
   - There is no inactive/revoked membership test. Add an administrator whose membership is disabled after initially succeeding, and assert read/send/receipt denial in the same identity session.
   - Only one station has private operational records. The second administrator is denied the first station, but no second-station positive path exists. Seed distinct orders, conversations, messages, and receipts for both stations; verify each administrator and customer sees exactly their permitted rows.
   - Receipts test an explicit forged `user_id`, the owner's timestamp refresh, and the administrator's inability to read the customer's receipt (lines 31–43). They do not test an otherwise-valid own receipt insert into another station/customer's conversation, cross-party updates, or denied receipt access for rider/Super Admin. Assert zero updated rows and unchanged stored timestamps for invisible targets; RLS UPDATE commonly filters rows rather than raising an error.
   - Anonymous visibility tests only two default-active stations (line 45), not product visibility or any hidden directory record. Add the positive and negative directory cases from finding 1.

These are gaps in regression evidence, not demonstrated bypasses in the currently inspected private-data policies. Update the report's coverage claims to match the expanded assertions.

## Minor

1. **Constraint tests do not isolate both composite foreign keys.** The final item test supplies `station2` with an order and product both from station1; either FK alone rejects it. Add separate cases with a valid order/station pair and a wrong-station product, then a valid product/station pair and a wrong-station order. Also test an order-linked conversation whose station or customer differs from that order, and a valid pickup insert. The inspected SQL has the correct constraints; these tests would protect them independently.

## Confirmed by source inspection

- All nine public tables and both private authorization tables enable RLS. Client grants are explicitly reset and narrowed. Private tables have no client table privileges or policies.
- Supabase Auth owns identity. Signup/backfill only create the customer role and profile; editable `raw_user_meta_data` is not read by authorization code. Profile edits cannot change identity or roles.
- Definer helpers reside in `private`, have an empty fixed search path, explicitly use the current Auth UID, and return limited authorization booleans. PUBLIC/anonymous execution is revoked; authenticated execution is limited to helpers required by policies. Schema creation is not granted to clients. Trigger functions have execution revoked and validate trigger context; the receipt trigger uses invoker privileges and the database clock.
- The station-admin branch requires both the authoritative role and a current active membership for the exact station. Customer access requires the authoritative customer role and matching owner. Explicit Super Admin denial applies before either branch; rider denial applies to conversations/messages/receipts, including riders who also have customer access. Public catalog prices are intentionally public; there are no payment tables.
- Messages can insert only conversation and body. Sender, ID and time use database defaults; client updates/deletes have no grants. Receipts permit only their own participant rows, with immutable conversation/user columns and explicit UPDATE USING/WITH CHECK conditions. Profile/address updates also have explicit checks and column grants protecting ownership.
- Orders/items/conversations/catalog/roles/memberships have no client write grants. Clients cannot set order prices/statuses or provision themselves. Delivery requires an owned address; pickup excludes address, driver and delivery-only statuses. Composite foreign keys bind orders, items, products and optional conversation orders to their station/customer. Item totals use bigint generated arithmetic.
- The documented future trusted backend obligations—atomic order creation, authoritative prices, fees, non-empty baskets, conversation authorization, role provisioning and dispatch—are legitimate scope limits. This foundation does not claim to implement those operations or connect the UI to live Auth/messaging. No additional full order-processing API is required for this review.

## Primary documentation and inference limits

The local messaging/fulfillment contract and approved design define the narrow foundation scope; the earlier project plan supplies the approved-directory requirement. Directory approval is therefore an inference from the project's existing requirements, while the missing field/default/policies are directly observed SQL facts.

[Supabase RLS documentation](https://supabase.com/docs/guides/database/postgres/row-level-security) confirms that grants and RLS must both be checked for exposed tables. [PostgreSQL row-security documentation](https://www.postgresql.org/docs/current/ddl-rowsecurity.html) confirms owner bypass, default denial, and policy combination behavior; this is why the suite's SET ROLE checks matter. Hosted Auth JWT handling, Data API schema exposure, Realtime, production grants/advisors, and deployment compatibility remain unverified, as the migration report correctly states.


## Resolution verification

The coordinating implementation resolved the approval and authorization-test findings. New stations have registration_status pending; public station/product policies require approved and active. Registry and own-station metadata policies are separate from public directory access. Participants can read same-conversation receipts but only create/refresh their own.

The revised suite actually switches client database roles and checks both station/customer sides, active/inactive/revoked memberships, a Super Admin with customer and station-admin roles plus active membership and owned records, rider-owned threads, cross-conversation receipts, timestamp and ownership forgery, independent item/order/product foreign keys, order-linked conversation mismatches, and valid pickup. It also rejects whitespace-only or overlong messages.

Fresh root verification: 107 PostgreSQL/RLS assertions pass against the actual migration. This is coordinating implementation verification, not a second independent review or hosted Supabase integration test. No project was connected or changed.