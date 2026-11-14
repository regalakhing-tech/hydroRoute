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
