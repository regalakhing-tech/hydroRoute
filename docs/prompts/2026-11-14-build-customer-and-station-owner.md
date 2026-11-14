# Prompt: build every customer and station-owner function in HydroRoute

Paste everything below the line into Claude Code (or another coding agent) opened at the project root. The work is large. If the session runs out of context, start a new session with this same prompt and the line "Continue from the first unfinished phase in docs/prompts/progress.md".

---

You are a senior full-stack engineer. Finish HydroRoute, a web ordering and delivery system for water refilling stations in Hamtic, Antique. It is also a thesis project, so the code must match what the thesis describes.

## 1. Goal

Every page and button on the **customer** side and the **station admin (business owner)** side must work with real, saved Supabase data when `APP_PREVIEW=false`. No customer or station-admin screen may show sample data from `app/UI/CustomerData.php` or `app/UI/StationData.php` outside preview mode.

"Works" means: the action is saved in Supabase, it survives a page reload and a different Vercel instance, the other side sees it (the customer's order appears in that station's queue, the station's status change appears on the customer's tracking page), and it is protected by row-level security and server-side checks.

The rider side is in scope only where the station's delivery flow needs it (see Phase 6). The Super Admin side already works; do not break it.

## 2. What exists today (checked 14 November 2026)

Stack: plain PHP 8.5 (no framework), Supabase (Auth + PostgreSQL + PostgREST), Tailwind CSS 4, vanilla JavaScript, SweetAlert2. It is deployed on Vercel with the `vercel-php@0.9.0` runtime. Functions run in `hnd1` (Tokyo), next to the Supabase project (`ap-northeast-1`).

Already working with real data:

- Customer signup with an emailed 6-digit code, login, logout, password setup from an invite link (`app/Controllers/AuthController.php`).
- Sessions stored in Supabase `public.app_sessions` on Vercel (`app/Services/SessionStore.php`); native file sessions locally.
- Station registration form, Super Admin approval or rejection, owner invitation email (`RegistrationController`, `RegistryController`, `app/Services/Registry.php`, `app/Services/Invitations.php`).
- The public station directory on the landing page and `/customer/stations` (`app/Services/StationDirectory.php`).
- The station admin's driver list, add driver, and set driver password (`StationController`).
- Super Admin registry pages.

Still sample data or browser-only (this is your work):

- Customer: overview, station page and catalogue, checkout, orders, tracking, addresses, notifications, account, messages.
- Station admin: overview, order queue, deliveries, pickups, customers, messages, products, schedules, reports (sales, orders, deliveries), settings (profile, service areas, order rules), trip page.
- Messages on both sides are saved only in the browser's localStorage (`public/assets/js/messages.js`).
- Prices come from the PHP constant list in `app/UI/Products.php`. They must come from each station's `products` rows.

Database objects already applied to the live project:

- `supabase/migrations/20261102144011_access_fulfillment_messaging.sql`: `private.user_roles`, `private.station_memberships`, `profiles`, `stations`, `addresses`, `products`, `orders`, `order_items`, `conversations`, `messages`, `read_receipts`, RLS helpers in schema `private`.
- `supabase/migrations/20261114120000_station_registry_drivers.sql`: extra station columns (slug, barangay, phone, email, weekday/sunday hours, closed_notice), `station_registrations`, `drivers`, RPCs `my_access`, `submit_station_registration`, `review_station_registration`, `provision_station_member`, `provision_platform_admin`, `register_driver`.
- `supabase/migrations/20261114130000_app_sessions.sql`: session store.

Read all three migration files before you design anything new. Extend them; do not recreate existing tables.

## 3. Read these first

1. `docs/superpowers/plans/2026-11-01-hamtic-water-station-php-supabase.md`, called "the system plan" below. It is the source of truth for business rules. Read it all, especially §3 M11 (distance, fee, SSTF) and M12 (inbox, reservations), §4 roles and schedules, §5 architecture, §6 database plan and its "Gaps" list, §8 order processing rules, §9 order statuses, §11 RPC contracts and §13 acceptance scenarios.
2. `Hamtic Water Delivery Blueprint.md`: the original requirements (validation V1–V7, priority queue, notifications).
3. `docs/ui-contract.md`, `docs/messaging-fulfillment-contract.md`, `docs/deployment.md`.
4. `routes/web.php`, `public/index.php`, `app/Controllers/Controller.php`, `app/Controllers/UIController.php`, `app/Services/SupabaseClient.php`, `app/Services/Gateway.php`, `tests/support/fakes.php`.
5. Every view in `app/Views/customer/` and `app/Views/station-admin/`, and the sample arrays they read.

Where the system plan and this prompt disagree, follow the system plan and say so in your report.

## 4. Hard rules (never break these)

Security and privacy:

- Station isolation: a station admin reads and changes only their own station's data, decided from `private.station_memberships` on every request. Never trust a station ID, role, price, distance, fee, total or user ID sent by the browser.
- Super Admin never sees orders, order counts, payments, revenue, conversations, rider locations or customer histories. Keep that true in RLS, RPCs, PHP and every new page.
- Customers see only their own addresses, orders, notifications and conversations.
- Every new table has RLS enabled. Workflow tables (orders, order items, status logs, trips, attempts, payments, reservations) get **no direct insert/update/delete grants** for `anon` or `authenticated`. All writes go through `security definer` RPCs with `set search_path = ''`, explicit actor and membership checks, and explicit `grant execute` to the right role only. Follow the pattern in the existing migrations.
- Use the user's access token (`$this->db->rpc(..., $token)` with `CurrentUser::token()`) for all user actions. Use `adminRpc` (secret key) only for provisioning and background jobs, as today.
- Every POST checks CSRF with `$this->csrfFailed(...)`. Every value printed in a view is escaped (`$e(...)` or `UI::e(...)`). Never print exception messages to users; log the class name only, as the existing code does.
- Keep the Content-Security-Policy in `app/Core/Response.php`. If you add Leaflet maps, vendor Leaflet's JS and CSS into `public/assets/vendor/`, add them to the asset allowlist in `public/index.php`, and widen only `img-src` to `'self' https://tile.openstreetmap.org` (and `data:` if Leaflet's marker icons need it). Show the OpenStreetMap attribution.
- No secrets in code, views, logs, Git or test fixtures.

Data rules:

- Money is stored as integer centavos, like `price_centavos` today. Never mix centavos with `numeric(12,2)`.
- Use `timestamptz` for instants and Asia/Manila for local dates, hours and "today".
- Prices, item subtotals, distance (Haversine, rounded to 0.1 km), delivery fee and totals are calculated **in the database**. A PHP preview is allowed only for display, and the server result wins.
- Each order saves snapshots of product name, unit price, address text and pin, distance, fee, fee-rule version and total.
- Mutating RPCs take a `request_key uuid` (idempotency; unique per station and customer) and, where the plan says so, an `expected_version integer` (optimistic locking). Retrying with the same key returns the first result instead of creating a duplicate.
- Every order status change writes a row to `order_status_logs` (station, order, from, to, actor, reason, time) in the same transaction.
- Status names and allowed transitions are exactly those in system plan §9. Pickup orders never get a driver, a trip or a delivery fee.

Default business values (the stations have not confirmed them yet). Store them per station in `station_settings` or `delivery_fee_rules`, never as PHP constants:

| Setting | Default |
| --- | --- |
| Delivery fee bands | under 1 km PHP 5; 1–3 km PHP 10; over 3 km PHP 15; once per delivery order |
| Maximum delivery distance | 10 km |
| Maximum containers per order | 20 for the whole order |
| Scheduling window | today up to 7 days ahead, inside the station's hours |
| Duplicate window | same customer, items, address and schedule within 30 minutes: `on_hold` |
| Priority | P = U + A + C (system plan §8, Stage 2), institution bonus off by default |
| Overdue alert | waiting over 120 minutes |
| Default catalogue | the 7 products and prices in `app/UI/Products.php` |

## 5. Codebase conventions (follow them; do not add a framework)

- Routes: `routes/web.php`. Role pages are generated from `app/UI/Pages.php`. Add POST routes next to the existing ones.
- Controllers extend `App\Controllers\Controller` and use `back($path, $type, $message)` (flash + 303 redirect), `csrfFailed()`, `member($role)` and `input()`. Return `Response::view`, `Response::redirect` or `Response::json`.
- Services hold business calls and take a `Gateway` (`select`, `rpc`, `adminRpc`) so tests can pass `FakeGateway`. Prefer one RPC per workflow over several REST calls. If you truly need a plain insert/update/delete REST helper, add it to `SupabaseClient` and the `Gateway` interface, and update `FakeGateway`.
- `UIController::page()` builds `$sample` for each role. Replace sample keys with real data the way `stationDrivers()` does: map database rows into the shape the view already uses, so views change as little as possible. Preview mode (`APP_PREVIEW=true`) must keep showing the sample data, because the automated page tests depend on it.
- Customer pages currently skip the token (`$token = $role === 'customer' ? '' : ...`). Change this so customer pages use `CurrentUser::token()` too.
- Views: `app/Views/{role}/{screen}.php` with helpers in `app/UI/UI.php`. Keep the existing look; reuse components (cards, badges, tables, empty states, icons).
- JavaScript: plain files in `public/assets/js/`, registered in the asset allowlist in `public/index.php`. Forms must work without JavaScript; JavaScript only improves them (live totals, polling, maps).
- After adding Tailwind classes, run `npm run build:css` and commit `public/assets/css/app.css`.
- Errors: show friendly SweetAlert flash messages through `Session::flash`. Every list has an empty state, and every Supabase failure shows a "could not load, please refresh" message instead of a 500 page.
- Mobile first. Customer and rider pages must be usable at 360 px wide.

## 6. Database work: how migrations reach Supabase

- Write each phase's SQL as a new file `supabase/migrations/2026MMDDHHMMSS_<name>.sql`. Make it idempotent where practical (`create table if not exists`, `create or replace function`, `drop policy if exists` before `create policy`) so it can be re-run.
- You cannot apply it yourself. The user's network blocks direct Postgres connections, and write SQL through the CLI is not allowed. When a migration is ready, **stop and ask the user** to paste the whole file into the Supabase dashboard SQL Editor and run it.
- After the user confirms, verify with read-only queries only, for example:
  `npx supabase db query --remote --read-only "select proname from pg_proc where proname = 'submit_order'"`
  Check tables, RLS (`relrowsecurity`), grants (`has_function_privilege`, `has_table_privilege` for `anon` and `authenticated`) and policies.
- Never write SQL that drops or rewrites existing data without asking first. Widen the existing `orders` status check constraint (system plan §6 Gaps, item 1) with `alter table ... drop constraint ... add constraint`, keeping all current rows valid.
- Do not deploy PHP that calls a new RPC before its migration is confirmed applied.
- Seed data: every approved station (existing ones too) must get the 7 default products and a default fee-rule version and settings row. Do this inside the approval RPC for new stations, plus a one-time backfill statement in the migration for existing stations.

## 7. Phases

Do the phases in order. Each phase ends with migrations applied and verified, tests passing, `docs/prompts/progress.md` updated (which phase is done, what is left, any decisions), and a Git commit. Do not start the next phase with failing tests.

### Phase 1: Inventory and design (no code yet)

1. List every customer and station-admin page, every button and form on it, and the sample key it reads. Save the list as a checklist in `docs/prompts/progress.md`.
2. Design the full database delta for Phases 2–8 against the system plan §6 table list and §11 RPC list: tables, columns, constraints, indexes, RLS policies, RPC signatures and grants.
3. Show the design to the user and get approval before writing migrations.

### Phase 2: Station setup (business owner)

The station must be able to configure itself before it can take orders.

- **Settings › Profile** (`/station-admin/settings`): edit public name, phone, email, address, barangay and description. Set the station **map pin** with a Leaflet map plus "use my current location". Validate that the pin is inside Hamtic (agree a bounding box, document it). RPC: `save_station_profile`, `save_station_location`.
- **Settings › Service areas**: choose the Hamtic barangays the station delivers to (a `barangays` reference table seeded with Hamtic's barangays, plus `station_service_areas`).
- **Settings › Order rules**: maximum containers per order, maximum delivery distance, delivery fee bands (contiguous, non-overlapping, saved as a new rule version each time; old versions kept), institution bonus on or off. RPC: `save_station_settings`, `save_delivery_fee_rules`.
- **Products** (`/station-admin/products`): list, add, edit price, pack text, description and image choice (from the existing images), returnable-container flag, availability on or off. Never hard-delete a product used by an order; deactivate it instead. Price changes affect only new orders.
- **Schedules** (`/station-admin/schedules`): weekly hours with up to 2 windows per day and rest days, dated no-service days and maintenance with a public notice, an immediate "close now" with an optional end, and "reopen". Show a preview of the next 7 bookable days. Follow system plan §4 "Station schedules". RPCs: `save_station_hours`, `set_station_closure`, `reopen_station`, `get_station_availability`. Replace the simple `weekday_open`/`closed_notice` logic in `StationDirectory` with `get_station_availability` so the directory, station page and checkout all use one rule.

Acceptance: the owner configures everything, reloads, and sees it saved; a second station's admin cannot read or change any of it (test through the RPCs with two accounts); the customer directory shows the new hours and availability.

### Phase 3: Customer profile, addresses and station catalogue

- **Account**: edit display name and mobile number (Philippine format, stored as `+639XXXXXXXXX`), change password through Supabase Auth, log out everywhere.
- **Addresses**: add, edit, delete and set a default. Each address has a label, barangay, street/purok, landmark and a **map pin** (Leaflet or device location, saving `pin_source`). An address without a pin can be used only for pickup. Customers can delete an address used by an old order (the order keeps its snapshot); use soft delete if a foreign key requires it.
- **Find stations / station page**: show only approved stations, live availability from `get_station_availability`, that station's active products and prices, served barangays, and the distance and fee from the customer's default address (`quote_delivery`).
- **Overview**: real recent orders, the active order's live status, unread messages and unread notifications.

Acceptance: a new customer can sign up, add a pinned address, open a station and see that station's real prices and their own delivery fee.

### Phase 4: Ordering, checkout and tracking (customer)

- **Checkout** (`/customer/checkout`): choose products and quantities, delivery or pickup, address (delivery), ASAP or a scheduled time inside the station's valid windows, payment = cash on delivery or on pickup, and an optional note. Show subtotal, distance, delivery fee and total before submission, using `quote_delivery`, recalculated live as items change.
- **Submit** with `submit_order(station_id, payload, request_key)` implementing system plan §8 Stage 1 (V1–V7 plus the distance rule). On success, redirect to tracking with a success flash. On validation failure, return field errors and keep the form filled. Show station closed, out of range, over the container limit and product unavailable as clear messages.
- **Duplicate check**: a possible duplicate is saved as `on_hold`. The customer sees the original order and chooses "Yes, I want another" (`confirm_duplicate`) or "Cancel" (`cancel_order`).
- **Scheduled orders** (future time) are saved as `scheduled` and released later by the station job (Phase 8).
- **My orders**: real list with filters (active, completed, cancelled), station name on every order, a readable order number unique per station (for example `HMT-0001`).
- **Tracking** (`/customer/tracking?order=...`): timeline from `order_status_logs`, items, fee as its own line, total, driver name when out for delivery, pickup instructions for pickup. Auto-refresh status every 30 seconds with a small JSON endpoint. Cancel button only while the status allows it (`cancel_order` with a reason).
- **Notifications**: in-app notifications written by the order RPCs (confirmed, preparing, ready, out for delivery, delivered, cancelled, reservation decisions, new messages). Unread count in the navigation, mark one or all as read.

Acceptance: two browsers. The customer places an order, and the station admin sees it in the queue without a page refresh beyond the normal polling. Retrying the same submit (double click, refresh, network retry) creates exactly one order.

### Phase 5: Station operations (business owner)

- **Overview**: today's real numbers for this station only: new, preparing, ready, out for delivery, completed, cancelled, cash collected, unverified cash, overdue alerts, station open/closed state, and drivers available.
- **Order queue** (`/station-admin/orders`): preparation queue sorted by the database priority function (`get_preparation_queue`), with the U, A and C explanation and "position is an estimate" wording. Actions: start preparation, mark ready, cancel with reason (system plan §9 operational exception), confirm or decline on-hold duplicates. Show overdue (over 120 minutes) alerts that need a reason to dismiss.
- **Pickup orders**: a ready-for-pickup list, mark collected, then complete after cash is verified.
- **Deliveries / dispatch**: `preview_trip` proposes a free rider and a batch of ready orders (same barangay first, within vehicle capacity, priority order) and shows the SSTF stop order from the station pin with each leg's distance. The admin may remove orders or move one overdue stop to the front with a reason, then confirms; `dispatch_trip` saves the trip, its stops and the order changes atomically. Orders larger than every vehicle's capacity stay visible with a capacity warning. Follow system plan §8 Stage 4 exactly.
- **Trip page** (`/station-admin/trip`): live trip status, stops, rider's last known distance, results per stop.
- **Completion and cash**: verify the cash applied, tendered and change and the empties per delivered order, then `complete_order`. Show flagged delivery-fee mismatches (higher measured fee) for review.
- **Failed attempts**: review the reason, then `approve_reattempt` or cancel.
- **Customers**: customers who ordered from this station only, with order count, last order and total spent at this station, and a station-specific classification (household or institution).

Acceptance: the full delivery flow (confirmed › preparing › ready › out_for_delivery › delivered › completed) and the pickup flow (confirmed › preparing › ready › collected › completed) both work end to end, and a second station sees none of it.

### Phase 6: Rider screens the station needs (supporting scope)

Without these, dispatched orders can never be delivered. Keep them small:

- **My trip** (`/rider`): assigned stops in SSTF order with address, landmark, items, amount to collect, phone and the distance from the station. With permission, the browser Geolocation API shows the live distance to the next stop and sends a position every 30 seconds or 50 m during an active trip only (`record_rider_location`). The page explains why location is needed and keeps working when permission is denied.
- **Arrived** (`mark_arrived`): applies the automatic fee decision from system plan M11 (unchanged, lowered, flagged, or fallback) and shows the amount to collect.
- **Record result** (`record_delivery`): delivered, with cash tendered and calculated change plus empties returned; or failed, with a required reason. Confirm before submitting. Idempotent.
- **Close trip** (`close_trip`) and **History**.

Only the assigned rider can act on a stop. Riders never see revenue, conversations or other riders' trips.

### Phase 7: Messages and reservations (both sides)

Replace the localStorage preview with the live design in system plan M12:

- `start_conversation`, `send_message` (2,000 characters, plain text, topic tag, optional order link, 20 messages per minute), `list_inbox` (last message, unread count, search, topic and unread filters), `get_messages(after)` and `mark_conversation_read`.
- Polling: every 10 seconds in an open conversation, every 60 seconds for the navigation badge, through small PHP JSON endpoints that keep Supabase tokens on the server.
- The conversation shows the station's closed notice and next opening when the station is closed.
- **Reservations**: the customer fills a short form in the chat (date and time, delivery or pickup, items, address, note) that becomes a reservation card. The station admin accepts (`respond_reservation` creates one `scheduled` order atomically after rechecking availability, prices and fee), declines with a reason, or replies. The customer can cancel while it is `requested`. Unanswered reservations expire (Phase 8).
- Riders and Super Admin have no access to conversations, in RLS and in PHP.
- Keep `tests/messages-dom.mjs` and `tests/messages-regressions.mjs` passing in preview mode, or update them to the live behaviour with equal coverage.

### Phase 8: Reports and scheduled jobs

- **Reports** (station only): sales (completed-order totals, cash collected, verified payments, delivery fees as separate measures, so nothing is double counted), orders (by status, fulfillment, product, day) and deliveries (trips, stops, failed attempts, distance travelled, average delivery time). Add date range filters, CSV export and a print stylesheet. Calculate the numbers in SQL views or RPCs scoped to the caller's station.
- **Station jobs**: `run_station_jobs(station_id)` and a Supabase Cron schedule (every minute or every 5 minutes, as the plan says) that release scheduled orders into valid service windows, flag orders affected by closures, refresh queue scores, expire unanswered reservations, and delete rider locations older than 30 days. Give the user the Cron SQL to run in the SQL Editor as well.

### Phase 9: Hardening, tests and release

- Remove every remaining sample-data path for customer and station-admin outside preview mode. Prove it: with `APP_PREVIEW=false`, grep the rendered pages for sample names (Maria Santos, Ana Dela Cruz, HMT-104, Guintas Water Station) and find none unless real data contains them.
- Run the full test suite and the security checks below. Update `docs/deployment.md` and the "Current implementation state" table in the system plan.
- Deploy with `vercel deploy --prod --yes` only after every migration is applied, then run `node tests/deployment-smoke.mjs` against production.

## 8. Testing requirements

Follow the existing test style (plain PHP scripts with `check()`, Node scripts with `node:assert` and jsdom). Add tests with every phase; do not leave them for the end.

- **PHP unit tests with `FakeGateway`** for every new controller and service: CSRF rejection, validation messages, the correct RPC name and parameters, error handling when Supabase fails, and redirects.
- **SQL behaviour tests**: extend `supabase/tests/security.mjs` / `registry.mjs` (PGlite) or add new ones for the order RPCs. Cover price tampering, fee and distance tampering, products and addresses from another station or customer, double submission with one request key, invalid status transitions, stale `expected_version`, closure versus order races, capacity limits, and SSTF ordering with fixed coordinates.
- **Isolation tests**: two stations, two admins, two customers, one rider each, one Super Admin. Every list and every RPC must deny the cross-station and cross-customer cases, and Super Admin must be denied all transactional reads and writes.
- **Priority and SSTF fixtures**: reproduce the blueprint's priority example for one station with a fixed evaluation time, and a 5-stop SSTF example with known coordinates and expected order, including the tie-break rule.
- **Preview-mode regression**: `php tests/ui-smoke.php`, `node tests/ui-dom.mjs`, `node tests/fulfillment-dom.mjs`, `node tests/messages-dom.mjs` and `node tests/product-pricing-smoke.mjs` against `APP_PREVIEW=true php -S 127.0.0.1:8000 public/router.php` must still pass.
- **Existing suites**: `php tests/auth.php`, `php tests/registry.php`, `php tests/session-store.php`, `php tests/product-catalog.php`.
- Before you call a phase done, run its tests and paste the passing output summary in your report. If something fails, say so with the failing line; never claim it passes.

## 9. When to stop and ask the user

- A migration is ready to be run in the SQL Editor.
- A business rule in the system plan is marked "confirm with the stations" and the default above does not cover it.
- A change would delete or rewrite existing rows, change an existing RPC's signature that live code uses, or widen what Super Admin can see.
- You find a conflict between the system plan, the blueprint and the thesis.

Otherwise keep working. Do not ask for permission to follow this prompt.

## 10. Report at the end of every phase

Keep it short and plain:

1. What now works, as user actions ("A customer can ..."; "A station owner can ...").
2. Migrations written, and whether the user has applied them (verified by read-only query: yes or no).
3. Tests run and their results.
4. What you skipped or could not verify, and any risk.
5. The next phase.
