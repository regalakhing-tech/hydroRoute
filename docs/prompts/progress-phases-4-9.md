# Progress: Phases 4–9 (orders, operations, rider, messages, reports, release)

Branch `feat/orders-dispatch-messaging`, built in a separate worktree while Phases 1–3 continue on `feat/live-station-workflows`. This file is kept apart from `progress.md` so the two branches merge without conflicts.

## How this branch depends on Phases 2–3

- It builds on the Phase 2 objects in `20261114155714_station_setup.sql`: `private.envelope`, `private.owner_guard`, `private.availability`, `private.bookable_windows`, `station_settings`, `delivery_fee_rule_sets`/`delivery_fee_rules`, `station_counters`, `mutation_requests`, `notifications`, `riders`, `station_membership_roles` and the extended `products`.
- Local SQL tests ran against an uncommitted copy of that Phase 2 file. Rerun every `supabase/tests/*.mjs` suite after both branches merge.
- My migrations use timestamps from `20261120000100` onward so they sort after the Phase 3 migration.
- Phase 3 owns the customer address and profile work. `20261120000100_orders_tracking.sql` adds only the columns it reads, each with `add column if not exists`: `addresses.barangay_id`, `latitude`, `longitude`, `landmark`, `deleted_at`, and `profiles.phone`. If Phase 3 uses different names, rename them in Phase 4 before applying.
- Phase 4 defines `quote_order` and `quote_delivery` with `create or replace` on one pricing engine, `private.price_order` with `private.delivery_terms`, so the checkout quote and `submit_order` cannot disagree. A Phase 3 station page should call these instead of its own version. `quote_delivery` returns `data.eligible`, `code`, `message`, `distance_km`, `distance_display`, `fee_centavos`, `fee_version` and `max_distance_km`.
- `get_navigation_counts` is defined here. A Phase 3 version, if one exists, is replaced.
- Checkout reads the basket from `qty[<product uuid>]=n` or from bare `<product uuid>=n` keys. The Phase 3 station page can link to `/customer/checkout?station=<uuid>&qty[<id>]=2`.

## Phase 4: ordering, checkout and tracking (customer) — built locally, migration not yet applied

What works once the migration is applied:

- A customer opens `/customer/checkout?station=<uuid>`, chooses products and quantities, delivery or pickup, a saved address, ASAP or a time up to 7 days ahead, and a note. Before submitting, they see the database quote: items, distance, fee and total. The totals update as they change the form, and an "Update total" button does the same without JavaScript.
- `submit_order` checks V1–V7 and the distance rule in the database. The checks cover: approved station; accepting ASAP or a valid window and cutoff; a valid customer phone; products owned by the station and active; a whole-order limit; an owned, pinned address in a served barangay within the maximum distance; and load units for delivery (D7).
- The order saves snapshots of the products, the address, both pins, the distance, the fee, the fee version and the total. Order numbers are `HMT-0001` per station, taken from a locked counter.
- Retrying with the same request key returns the first order. Reusing a key with different content is a conflict.
- A repeat order within 30 minutes is saved as `on_hold`. The customer sees the original order and chooses "Yes, I want another" (`confirm_duplicate`) or "Cancel this one" (D5: only the customer can confirm).
- A future-day order is saved as `scheduled`. A later time on the same day is saved as `confirmed` (D4).
- The customer can cancel `on_hold`, `scheduled` and `confirmed` orders with a reason. The owner can also cancel `preparing` orders; Phase 5 adds the D9 exception for `ready` orders.
- The customer can reschedule an order that is `scheduled` or `confirmed`.
- My orders: All, Active, Completed and Cancelled tabs, plus search.
- Tracking: a timeline from `order_status_logs`, the items, the fee as its own line, the total, the driver while the order is out for delivery, pickup instructions, a 30-second version poll, and cancel and reschedule forms.
- Notifications: written by every status change. Station staff notices use the `staff_` kind prefix. Customers can mark one notification read or all of them. `get_navigation_counts` returns the unread badge counts.

Files: `supabase/migrations/20261120000100_orders_tracking.sql`, `app/Controllers/LiveController.php`, `app/Controllers/OrderController.php`, `app/Services/Workspace.php`, `app/Services/Checkout.php`, `app/UI/OrderStatus.php`, `app/Views/customer/live/*.php`, `public/assets/js/live.js`, `routes/operations.php`. Existing files got one line each: the four customer preview views, `routes/web.php`, `app/Views/partials/head.php` and the `public/index.php` asset allowlist.

Tests:

- `node supabase/tests/orders.mjs`: 59 order checks passed. They cover fee boundaries 0.99/1.0/3.0/3.01 km giving 500/1000/1000/1500 centavos, tampering, idempotency, duplicates, schedules, a closed station, cancellation rules, isolation, Super Admin denial and no direct writes.
- `php tests/orders.php`: 29 order checks passed.
- Preview regressions (`ui-smoke`, `ui-dom`, `fulfillment-dom`, `messages-dom`, `product-pricing-smoke`, `messages-regressions`) and the existing PHP suites still pass.

## Phases 5 and 6: station operations, dispatch and rider screens — built locally, migration not yet applied

Migration: `20261120000200_operations_dispatch.sql`. It adds `customer_station_profiles`, `delivery_trips`, `trip_orders`, `rider_locations`, `delivery_attempts`, `payments`, `order_reviews` and `private.rider_reservations`. All have RLS on, no client grants, and RPC-only access. Deviation from the design: the separate `order_collections` table is merged into `payments`. Each collected order gets one row holding the cash applied, tendered and change, plus the expected and returned empties.

What works once the migration is applied:

- **Overview:** a station owner sees today's numbers for this station only: orders by status, cash collected and verified today, unverified cash, overdue alerts, open alerts, free riders and open/closed state.
- **Order queue:**
  - Orders are ranked by the single database function `private.score`, so P = U + A + C, with U/A/C chips and "position is an estimate" wording.
  - Owners can start preparation and mark orders ready.
  - Owners can cancel with a reason. That covers confirmed, scheduled, on-hold and preparing orders, and ready orders with no trip and no payment (D9).
  - Overdue alerts appear once an order waits more than 120 minutes. Resolving one needs a written reason.
- **Order panel:** shows items, the fee and distance, the score breakdown, the history, and the cash record with a verify form. It also has the fee review (D2: the review records why the quote stands and never adds a surcharge), reattempt approval after a failed delivery, and completion. Completion needs verified cash and no open fee or empties review.
- **Pickups:** start, mark ready, then record the cash and empties at the counter (full payment required). After that, verify the cash and complete the order.
- **Deliveries:**
  - `preview_trip` follows Stage 4. It takes the highest-priority ready order that is due within 60 minutes and assigns the free rider with the fewest trips today who can carry the whole order. It then adds same-barangay orders in queue order up to the vehicle's capacity.
  - An order larger than every free vehicle stays visible with a warning, and the next feasible order is proposed.
  - The owner can untick orders, pick another free rider, or move one overdue stop to the front with a reason.
  - `dispatch_trip` checks everything again under locks and saves the trip, the SSTF stops, the rider reservation and the order changes in one transaction.
  - Owners can verify cash and edit rider vehicles and capacity; edits are copied to the driver registration row.
- **Trip page:** stops in SSTF order with the result and fee decision for each, the last rider position, and closing the trip. If stops are still open, closing needs a reason and returns those orders to ready, paused for review.
- **Customers:** only customers of this station, with order counts, last order, amount spent at this station, and a household/institution classification for this station.
- **Rider:**
  - The rider sees their active trip with stops in SSTF order, the customer contact details, items, the amount to collect and the distance from the station.
  - During an active trip only, the browser shares its location about every 30 seconds or 50 m. The page explains why and still works when location is denied.
  - "I have arrived" applies the M11 and D2 fee rule. A lower measured fee is applied and the total drops. A higher one keeps the quote and opens a review. No GPS, or accuracy worse than 100 m, keeps the quote and records the reason.
  - Delivered needs full cash; change is calculated and the empties are counted. A different empties count opens a review.
  - Failed needs a reason. The order returns to ready, paused for review.
  - The rider can close the trip and see their history. Only the assigned rider can act, and riders never see revenue or other riders' trips.

Tests:

- `node supabase/tests/operations.mjs`: 73 operations checks passed. They cover:
  - the blueprint priority example: HMT-104/101/102/103/107 score 60/55/45/40/30, and aging at 9/10/49/50/60 minutes gives 0/5/20/25/25;
  - SSTF: B, A, C; a five-stop example with an exact tie broken by priority; and a forced first stop;
  - batching: 14 of 15 on the first trip and 5 of 5 on the second, the not-yet-due order waits, and the over-capacity order gets a warning;
  - the fee lowered, flagged and fallback cases, underpayment, and failed attempt then reattempt;
  - the full delivery and pickup flows with status logs, cash verification, review gates, D9 cancellation, isolation, and Super Admin denial.
- `php tests/operations.php`: 32 operations checks passed.

`app/Views/layouts/app.php` no longer shows "Orders and trips show sample data until ordering is connected" on live pages. If the station-setup branch changes the same footer line, merge by dropping that sentence.

## Phase 7: messages and reservations — built locally, migration not yet applied

Migration: `20261120000300_messages_reservations.sql`. It extends `conversations`, `messages` and `read_receipts` without breaking existing rows, and backfills message scope columns and `last_message_at`. It adds `reservations`, `reservation_items` and `private.message_rate_limits`.

Two changes affect existing behaviour:

- Clients can no longer insert or update messages and read receipts directly; every write goes through the RPCs.
- The old `stamp_receipt` trigger is dropped, so a read receipt advances only to a message the reader actually saw.

`private.insert_order` was extracted in the Phase 4 migration, which has not been applied yet. Submission and reservation acceptance share it.

What works once the migration is applied:

- **Chat:** a customer opens a conversation with a station, from a station link or `/customer/messages?station=<id>`. Customers and that station's admins can then:
  - send plain-text messages of up to 2,000 characters, with a topic and an optional link to one of that customer's orders at the station. A retry with the same request key sends once, and each sender is limited to 20 messages per minute;
  - search the inbox and filter it by topic or unread;
  - see unread counts;
  - see new messages within about 10 seconds, without the page reloading. Bodies are inserted with `textContent`.
- **Read state:** a message is marked read only when the reader has seen it, by a JavaScript POST or a "Mark as read" button. Viewing the page with GET never changes it.
- **Closed station:** the customer sees the closed notice and the next opening time in the conversation.
- **Navigation badges:** unread messages and notifications show in the side navigation, refreshed every 60 seconds.
- **Reservations:**
  - The customer fills in a form inside the chat: date and time up to 90 days ahead, delivery or pickup, products and quantities, address and note. It appears as a reservation card.
  - The owner accepts, or declines with a reason. Accepting reprices today's products and rechecks the normal booking window, hours, limits and distance (D6). It then creates one `scheduled` order, a system card and notices in one transaction.
  - The customer can cancel while the request is `requested`. Double acceptance and stale versions are refused.
- **Access:** riders and Super Admin are denied in SQL (`private.chat_role`) and in PHP.

Tests:

- `node supabase/tests/messages.mjs`: 47 messaging checks passed.
- `php tests/messages.php`: 15 messaging checks passed.
- Preview messaging (`messages-dom`, `messages-regressions`) still passes.
- `supabase/tests/security.mjs` asserts that customers can insert messages directly, which is now intentionally denied. Update it after merging; it already fails on the Phase 2 product seeding.

## Phase 8: reports and station jobs — built locally, migration not yet applied

Migration: `20261120000400_reports_jobs.sql`. Cron setup: `docs/station-jobs-cron.sql`, run separately after enabling `pg_cron`.

What works once the migration is applied:

- **Reports**, for the owner's station only:
  - The **sales** report keeps four measures separate: completion-date order totals, collection-date cash, verification-date cash and delivery fees. It also shows the unverified cash outstanding and a daily table.
  - The **orders** report breaks orders down by placed date, status, fulfillment, product, barangay and day, and lists cancellation reasons.
  - The **deliveries** report shows trips, stops, delivered and failed counts, failure reasons, planned and GPS distance, average delivery time, a table by rider and a daily table.
  - Every report has a date range filter and states its date basis. CSV export uses the same scoped RPC and protects formula cells. Print uses the existing print styles.
  - Other stations and Super Admin are refused.
- **Station job:** `run_station_jobs(station)` and `run_all_station_jobs()` are granted to the service role only. Each run covers one station in one minute slot and does nothing if repeated in the same slot. It:
  - releases due scheduled orders while the station is open, logged with `system_job = 'release_scheduled'` and no fake user;
  - raises overdue alerts;
  - expires unanswered reservations and notifies the customer;
  - deletes rider positions older than 30 days and clears arrival coordinates on old attempts;
  - records each run in `private.station_job_runs`.
  - Priority is calculated live on every queue read, so no separate five-minute job is needed.

Tests:

- `node supabase/tests/reports.mjs`: 24 report and job checks passed.
- `php tests/reports.php`: 10 report checks passed.

## Phase 9: hardening — done locally; release blocked on Phases 2–3 and the migrations

Done:

- `php tests/live-pages.php` renders all 20 live customer, station-admin and rider pages built here with empty real data: 247 live page checks passed. No page raises a PHP warning, and none shows a sample name (Maria Santos, Ana Dela Cruz, HMT-104, Guintas Water Station, Juan Reyes, and so on).
- The preview-only confirm dialog ("changes sample data") and the "Orders and trips show sample data" footer no longer appear on live pages.
- With `APP_PREVIEW=false`, logged-out requests to every new page redirect to the right login, and every JSON endpoint answers 401.
- `public/assets/css/app.css` is rebuilt with `npm run build:css` for the new classes.
- `docs/deployment.md` now gives the migration order and the Cron step. The implementation-state table in the system plan is updated.
- Full test run on this branch:

| Suite | Result |
| --- | --- |
| auth | 49 |
| registry | 59 |
| session-store | 7 |
| product-catalog | 16 |
| orders (PHP) | 29 |
| operations (PHP) | 32 |
| messages (PHP) | 15 |
| reports (PHP) | 10 |
| live-pages | 247 |
| SQL orders | 59 |
| SQL operations | 73 |
| SQL messaging | 47 |
| SQL reports and jobs | 24 |

  The preview suites `ui-smoke`, `ui-dom`, `fulfillment-dom`, `messages-dom`, `product-pricing-smoke` and `messages-regressions` all pass.

Still open, in order:

1. **Merge.** Finish Phases 2–3 on `feat/live-station-workflows`, then merge `feat/orders-dispatch-messaging`. Expect small conflicts in these files:
   - `routes/web.php`: keep both; this branch adds one `require operations.php` line.
   - `app/Views/layouts/app.php`: keep the station switcher and setup-error block; add this branch's nav badges and drop the sample-data footer sentence.
   - `app/Views/partials/head.php` and `public/index.php`: keep both script and asset lines.
   - `app/Controllers/UIController.php`: no change on this branch.
   - `public/assets/css/app.css`: rebuild after merging.
   - `docs/superpowers/plans/...` state table.
2. **After merging:**
   - Rename the Phase 3 address and profile columns in `20261120000100_orders_tracking.sql` if Phase 3 chose different names.
   - Point the Phase 3 station page at `quote_delivery` / `quote_order` and link to `/customer/checkout?station=<id>&qty[<product>]=n`.
   - Show live orders on the customer overview. That is Phase 3 scope; this branch did not touch `customer/overview.php`.
   - Rerun every `supabase/tests/*.mjs` and update `supabase/tests/security.mjs`. Direct message inserts are now denied, and Phase 2 seeds products.
3. **Database.** Run the six migration groups in order in the SQL Editor (see `docs/deployment.md`), verify each with read-only queries, then run `docs/station-jobs-cron.sql`.
4. **Release.**
   - Deploy with `vercel deploy --prod --yes` only after step 3.
   - Then run `node tests/deployment-smoke.mjs` and a real two-browser test: one customer and one owner placing, preparing, dispatching, delivering and completing an order, plus a pickup and a chat reservation.
