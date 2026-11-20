<!-- Planned by Codex gpt-6.1-sol (xhigh), 2026-11-20. Citations spot-checked by Claude. -->
# HydroRoute implementation plan

## Decisions recorded 2026-11-20 (user)

- Implement the whole plan with the recommended defaults below, with these overrides:
- **Batch size is 15, not 30.** One trip carries at most 15 load units (containers) and serves one barangay. The station setting `batch_capacity_load_units` defaults to 15; the effective trip capacity is the smaller of that and the rider's vehicle.
- **Scheduled times use 5-minute slots.** The checkout lists 8:00, 8:05, 8:10 and so on within the station's hours. One customer per slot per station: once another customer books a slot, it shows as taken and cannot be chosen. The same customer may place more than one order in their own slot. Slots count by 5-minute bucket, so a crafted 8:07 request competes with 8:05.
- **Customer copy:** "As soon as possible (ASAP)" becomes "Wait for your turn" ("No need to pick a time…"). Internal values stay `asap`.

## Status 2026-11-20

Done (code + tests; SQL applied to the hosted project on 2026-11-20 with `npx supabase db query --remote -f`):
- `supabase/migrations/20261122000100_batch_slots_capacity_cancel.sql`: batch ceiling (M14), 5-minute slots, daily refill limit with per-date overrides (M15, compute-on-demand from `orders.expected_empties` instead of a reservation state machine), customer cancel window (M16), plus a trigger backstop for races. Tests: `supabase/tests/capacity.mjs`.
- Order rules page fields, checkout slot picker (`fulfillment.js`), cancel deadline on tracking.

Done next (code + tests; `20261122000200` not yet applied to the hosted project):
- Per-date refill limit form on Schedules (`POST /station-admin/schedules/capacity`).
- M13 rider edit: `get_station_riders`, `save_rider` with name/phone, Drivers page Edit dialog with live status and active trip.
- M17 station lifecycle: `set_station_status` (approved ⇄ suspended, either → archived once nothing is open; archived is final and ends staff memberships), `my_access` keeps suspended stations for staff; Super Admin Details dialog actions (`POST /super-admin/stations/status`).
- M18 trusted device: opt-in checkbox on the station admin login, 30-day persistent cookie renewed daily, store TTL from `Session::ttl()`, refresh outages no longer log out. Simplified from the plan: reuses the existing hashed session row instead of a separate remember table; no "log out all devices" button yet (password change or Supabase sign-out revokes refresh tokens).
- M19 road map: `app/Services/RoadRouter.php` (OSRM `/route`, public demo server by default, `OSRM_URL` to override), `GET /rider/trips/route` (destination from the trip, origin from GPS or the station), Leaflet map with directions on the rider trip page, warning when OpenStreetMap roads look incomplete. No server-side cache or shared rate limit (client asks at most once per 60 s or 150 m).
- Tests: `supabase/tests/lifecycle.mjs`, `tests/remaining-features.php`, `tests/rider-map-dom.mjs`.

Master-plan check (2026-11-20, against `2026-11-01-hamtic-water-station-php-supabase.md`):
- Built now: customer "Change the time" on tracking (M5 reschedule; the RPC and route existed without a form), rider "km travelled" on the trip page (M11), phone sidebar opening again (Tailwind 4 `translate` fix), ALAB-style GPS and barangay detection for customer addresses and the station pin, order queue "Cancelled" filter.
- Then built (`20261122000400_email_outbox_resequence.sql`): order-update emails through `private.email_outbox` with backoff retries and opt-out via "order updates"; re-planning the remaining stops nearest-first after a failed stop (trigger) or with the rider's "Re-plan from here" button. Found and fixed: `pg_cron` was never enabled on the hosted project, so the station job (scheduled release, overdue alerts, reservation expiry) did not run; `supabase/manual/2026-11-20-enable-station-jobs.sql` turns it on.
- Road routing remains a demo-server add-on beyond the plan's straight-line scope.

Read-only review of `feat/live-station-workflows`; no files changed or tests executed. “Built” below means present in this branch, not independently verified in production.

**Approach:** extend the existing RPCs, trip tables, PHP controllers and vanilla JS. Preserve preview markup, classes, dialogs and layouts. Ship SQL as standalone migrations for the user to paste into Supabase SQL Editor, followed by read-only verification; do not use `db push`.

## Shared rules

- Keep mutations actor-checked, station-scoped, CSRF-protected, versioned and idempotent.
- Preserve existing RPC signatures where possible. Replace functions through new migrations; leave previous migrations unchanged.
- New public tables: enable RLS, explicitly grant only required reads, and deny direct workflow writes. New RPCs need explicit execution grants and internal authorization. Supabase now requires explicit grants for newly exposed tables. [Supabase guidance](https://supabase.com/changelog/45329-breaking-change-tables-not-exposed-to-data-and-graphql-api-automatically)
- **Unavoidable UI additions:** capacity fields/date limits, rider Edit action/dialog, trusted-device checkbox, station status action/reason, and rider map/directions. Reuse existing `panel`, `field`, `data-table`, `U::btn`, `U::badge`, `LiveForm` and dialog patterns.
- There is no separate staff workspace in the current role map: [`app/UI/Pages.php:6`](../../../app/UI/Pages.php#L6). Do not grant staff new privileges implicitly.

## 1. Batch water delivery — substantially built

**Current state:** `delivery_trips` and `trip_orders` already group orders, snapshot capacity and prevent duplicate active assignments. `preview_trip()` packs compatible whole orders from one barangay; `dispatch_trip()` rechecks capacity and saves SSTF sequences. See [`operations SQL:21`](../../../supabase/migrations/20261120000200_operations_dispatch.sql#L21), [`:379`](../../../supabase/migrations/20261120000200_operations_dispatch.sql#L379) and [`:407`](../../../supabase/migrations/20261120000200_operations_dispatch.sql#L407).

**Gap:** no station-wide 30-unit batch ceiling; capacity currently comes from the rider’s vehicle.

**Changes:**

- **NEW** `supabase/migrations/20261122000200_batch_capacity.sql`: add positive `station_settings.batch_capacity_load_units`, default 30. Enforce `min(station batch capacity, rider capacity)` in preview and dispatch; retain immutable trip capacity snapshots.
- Extend `app/Services/StationSetup.php` and `app/Controllers/StationSettingsController.php`; reuse `/station-admin/settings/order-rules` and `/station-admin/dispatch`.
- Add the field to `app/Views/station-admin/setup/order-rules.php`; reuse the existing load summary in `app/Views/station-admin/live/deliveries.php`.
- `public/assets/js/live.js`: refresh the displayed selection total when orders/rider change; SQL remains authoritative.

**Tests:** extend `supabase/tests/operations.mjs`, `tests/operations.php` and `tests/station-setup-dom.mjs`: multiple orders totaling 30 pass; 31 fail; a 15-capacity vehicle remains limited to 15; replay and concurrent dispatch cannot duplicate assignments.

**Risks:** existing oversized ready orders need a visible capacity warning. Preserve active trips; do not retroactively invalidate their snapshots.

## 2. Live rider road map — GPS exists; road routing missing

**Current state:** the rider page shows the next saved stop and address. JS watches GPS, displays straight-line distance and posts locations. Leaflet is already vendored for station/address pins. See [`rider/live/trip.php:17`](../../../app/Views/rider/live/trip.php#L17), [`live.js:278`](../../../public/assets/js/live.js#L278) and [`partials/head.php:14`](../../../app/Views/partials/head.php#L14). SSTF currently runs at dispatch: [`operations SQL:189`](../../../supabase/migrations/20261120000200_operations_dispatch.sql#L189).

**Gap:** no road polyline, route instructions, road ETA or deviation handling.

**Changes:**

- **NEW** `app/Services/RoadRouter.php`: native cURL and injectable fetch/clock, following `app/Services/Geocoder.php`; fixed configured HTTPS provider, bounded coordinates/timeouts/response size.
- Add `RiderController::route()` and `POST /rider/trips/route` in `routes/operations.php`. Resolve destination from the authorized active trip; never accept arbitrary destinations/provider URLs.
- **NEW** `supabase/migrations/20261122000700_road_route_cache.sql`: private short-lived route cache and shared provider request budget, accessible only through server-only RPCs.
- Extend `public/assets/js/live.js`: reuse one GPS watcher, move the marker continuously, request routes on stop changes or meaningful deviation, and ignore stale responses.
- Add a map/directions region within the existing address dialog in `app/Views/rider/live/trip.php`; load existing Leaflet through `partials/head.php`.
- Road routing follows saved SSTF stops. Use a routing **Route** endpoint, which preserves supplied waypoint order; do not use an optimizer that substitutes its own stop order. [OSRM API](https://project-osrm.org/docs/v5.24.0/api/#route-service)

**Tests:** **NEW** `tests/rider-map.php` and `tests/rider-map-dom.mjs`; extend `tests/operations.php` and `supabase/tests/operations.mjs`. Cover assignment authorization, coordinate order, stale responses, provider timeout/429/no-route, GPS denial and closed trips.

**Risks:** routing coordinates reach the provider; omit names, phones and order identifiers. Keep road estimates separate from existing fee calculations. Check Hamtic road coverage and vehicle suitability in field acceptance.

## 3. Gallon limit per date — missing

**Current state:** scheduling validates Manila dates, hours, cutoffs and booking horizon. Product pricing computes package count and vehicle load, but does not check date capacity or stock. See [`orders SQL:89`](../../../supabase/migrations/20261120000100_orders_tracking.sql#L89) and [`:141`](../../../supabase/migrations/20261120000100_orders_tracking.sql#L141).

**Changes:**

- **NEW** `supabase/migrations/20261122000300_daily_gallon_capacity.sql`:
  - Add nonnegative `station_settings.daily_gallon_limit`.
  - Add `products.gallon_units_per_pack` and snapshot the result on orders/items, separately from transport `load_units`.
  - Add `station_capacity_days(station_id, service_date, gallon_limit_override, version)` with a composite primary key.
  - Add order capacity state: `none`, `reserved`, `consumed`, `released`; index station/date/state.
  - Owner-only SELECT policy on capacity dates; writes only through checked RPCs. Customers receive remaining availability, never order/customer breakdowns.
- Use one capacity helper in submission, duplicate confirmation, reservation acceptance, rescheduling and cancellation. Quotes are advisory; committing operations lock and recheck.
- Extend `StationSetup.php`, `StationSettingsController.php`, `Workspace.php` and `OrderController.php`.
- **NEW route:** `POST /station-admin/schedules/capacity`.
- Reuse `setup/order-rules.php`, `setup/products.php`, `setup/schedules.php`, `customer/live/checkout.php` and existing reservation cards in `shared/live-messages.php`.
- Extend `station-setup.js` and `live.js` only for previews and inline feedback.

**Tests:** **NEW** `supabase/tests/capacity.mjs`; extend `supabase/tests/orders.mjs`, `messages.mjs`, `tests/orders.php`, `station-setup.php` and setup DOM tests. Cover exact limit, pickup consumption, reservation acceptance, date transfer, cancellation release and Manila midnight.

**Risks:** stock must be entered accurately. A daily allotment prevents overbooking against declared stock; it does not measure physical inventory automatically. Require explicit product conversion rather than treating every package as one gallon.

## 4. Customer cancellation window — preparation cutoff already built

**Current state:** customers can cancel pending/on-hold/scheduled/confirmed orders. Preparation blocks cancellation; owners retain a reasoned operational exception. See [`operations SQL:350`](../../../supabase/migrations/20261120000200_operations_dispatch.sql#L350). Tracking receives status-based `can_cancel`: [`orders SQL:432`](../../../supabase/migrations/20261120000100_orders_tracking.sql#L432).

**Gap:** no timed deadline or configurable duration.

**Changes:**

- **NEW** `supabase/migrations/20261122000400_customer_cancel_window.sql`: add bounded `station_settings.customer_cancel_minutes` and snapshot `orders.customer_cancel_until`.
- Recommend cancellation until the **earlier of 15 minutes after commitment or preparation starting**. Commitment means submission, intentional-repeat confirmation, or reservation acceptance.
- Use the same eligibility helper in `cancel_order()` and `get_customer_order()`, checked after locking. Rescheduling does not restart the clock.
- Preserve existing routes. Extend `OrderController::status()`, `StationSetup.php` and `StationSettingsController.php`.
- Reuse the cancellation panel in `customer/live/tracking.php` and rules fields in `setup/order-rules.php`. JS may disable an expired button; SQL decides.
- Unconfirmed duplicate holds remain withdrawable because they reserve no stock.

**Tests:** extend orders/operations SQL and PHP tests: just before/exactly at/after deadline, preparation race, replay after expiry, scheduled orders, reservation acceptance and capacity release.

**Risks:** grandfather existing orders under their current preparation rule; changing settings must not shorten an already promised window.

## 5. Add/update rider — creation built; editing incomplete

**Current state:** creation validates details, creates Auth credentials and registers both driver/rider records: [`StationController.php:77`](../../../app/Controllers/StationController.php#L77). `save_rider()` updates vehicle, plate, capacity and enabled state, but no view currently submits to it: [`operations SQL:602`](../../../supabase/migrations/20261120000200_operations_dispatch.sql#L602).

**Changes:**

- **NEW** `supabase/migrations/20261122000100_rider_profile_edit.sql`: extend `save_rider()` with station-local name/phone validation, version checks and audited before/after values; synchronize existing driver/rider fields atomically.
- Extend `StationOpsController::saveRider()` and `UIController::stationDrivers()` to load actual rider ID/version, availability and current trip. The current loader labels every active driver “Available”/“No active trip”: [`UIController.php:185`](../../../app/Controllers/UIController.php#L185).
- Reuse existing `POST /station-admin/riders/save`.
- **NEW** `app/Views/station-admin/live/drivers.php`: copy `station-admin/drivers.php`; add an Edit dialog using fields from `add-driver.php`. Keep the existing creation route/form.
- Reuse `app.js` dialog behavior; no new JS library.

**Tests:** extend `tests/registry.php`, `operations.php`, `live-pages.php` and `supabase/tests/operations.mjs`: creation rollback, cross-station denial, stale version, validation and synchronized updates.

**Risks:** retain trip capacity snapshots when a vehicle changes. Disabling a rider must block future dispatch while allowing the current trip to finish. Recommend keeping login email read-only until a verified Auth email-change flow is explicitly included.

## 6. Rename station — already built

**Current state:** the live profile has a Station name field and POST action: [`setup/profile.php:10`](../../../app/Views/station-admin/setup/profile.php#L10). `save_station_profile()` trims/validates the name, updates it under version checks and audits the mutation: [`station setup SQL:571`](../../../supabase/migrations/20261114155714_station_setup.sql#L571).

**Gap:** no missing core rename feature.

**Changes:** no new migration, route, view or JS. Verify the existing `/station-admin/settings/profile` flow and refreshed names across directory, workspace header, registry and rider pages. Change PHP only if those checks expose stale session-derived labels.

**Tests:** extend `tests/station-setup-integration.php` and `supabase/tests/station-setup.mjs`: rename readback, escaping, stale version, foreign owner and injected Super Admin membership.

**Risks:** preserve station ID/slug and original registration submission. Duplicate display names should not merge identities.

## 7. Persistent station sign-in — durable sessions exist; persistence missing

**Current state:** PHP uses an eight-hour lifetime and browser-session cookie: [`Session.php:17`](../../../app/Core/Session.php#L17). Durable storage exists, but unchanged sessions are not touched: [`SessionStore.php:88`](../../../app/Services/SessionStore.php#L88). Any refresh exception logs the user out: [`CurrentUser.php:31`](../../../app/Services/CurrentUser.php#L31).

**Changes:**

- Recommend an opt-in **30-day trusted-device session for station admins**, revocable and renewed during valid use.
- **NEW** `supabase/migrations/20261122000600_station_remember_sessions.sql`: extend `app_sessions` with server-controlled user/role/station scope, remember expiry, revocation and refresh lease/revision metadata. Keep session RPCs server-only and direct table access denied.
- **NEW** `app/Services/StationRemember.php`; extend `Session.php`, `SessionStore.php`, `CurrentUser.php`, `AuthController.php`, `public/index.php` and `config/app.php`.
- Reuse the existing hashed opaque session identifier as the device token; persist its cookie only after verified station-role login. Rotate identifiers, serialize refresh across Vercel instances, and recheck membership/revocation.
- Distinguish temporary Auth/network failure from invalid/revoked credentials; fail protected requests temporarily without erasing recoverable login state.
- Add a checkbox using existing login-form styling and `POST /station-admin/account/logout-all`; logout/password reset/archive must revoke persistence.

**Tests:** extend session/auth PHP tests; **NEW** `supabase/tests/remember-sessions.mjs`. Cover browser restart, inactivity, expiry, revoked membership, transient failures, concurrent refresh and unchanged customer/Super Admin behavior.

**Risk:** “forever” cannot survive explicit logout, revoked credentials or cleared browser data. Preserve Supabase refresh-token protections. [Session documentation](https://supabase.com/docs/guides/auth/sessions)

## 8. Scheduling against batch capacity — share feature 1’s implementation

**Current state:** dispatch capacity is enforced, but checkout checks only the package limit, default 20: [`station setup SQL:114`](../../../supabase/migrations/20261114155714_station_setup.sql#L114), [`orders SQL:150`](../../../supabase/migrations/20261120000100_orders_tracking.sql#L150).

**Changes:**

- Include booking-time checks in feature 1’s batch migration.
- Reject a delivery order whose whole transport load exceeds the station batch cap or every enabled, authorized vehicle’s capacity. A temporarily busy rider should not prevent future scheduling.
- Apply checks to quote, submit, duplicate confirmation and reservation acceptance.
- Reuse checkout errors, reservation feedback and dispatch warnings; no additional route/table/dependency.
- Preserve package limits independently: a 30-capacity trip may contain several orders even when each order permits only 20 packages.

**Tests:** extend orders/messages/operations suites: 30 versus 31, package count versus load, mixed products, unavailable vehicles and changed capacity between quote and commit.

**Risks:** reject oversized orders rather than silently splitting payments, fees, empties and delivery outcomes.

## 9. Super Admin removes a station — statuses exist; action missing

**Current state:** station status already supports `suspended` and `archived`; public reads require active/approved: [`base SQL:18`](../../../supabase/migrations/20261102144011_access_fulfillment_messaging.sql#L18) and [`:140`](../../../supabase/migrations/20261102144011_access_fulfillment_messaging.sql#L140). Registry controls currently approve/reject applications and resend invitations: [`RegistryController.php:24`](../../../app/Controllers/RegistryController.php#L24).

**Changes:**

- **NEW** `supabase/migrations/20261122000500_station_lifecycle.sql`: add registry version/change metadata and an audited Super-Admin-only status RPC with reason, request key and expected version.
- Recommend **suspend immediately, archive after outstanding work is resolved**. Suspension hides the station and blocks new bookings, preparation and dispatch; assigned riders can finish and owners can reconcile.
- Final archival requires no unresolved trips, orders or reservation requests. Return a generic blocker to Super Admin, without transaction details.
- Extend `Registry.php`, `RegistryController.php`, `UIController.php`, `StationContext.php` and relevant SQL guards.
- Update `my_access()`/login handling to permit narrowly scoped reconciliation while suspended; its current filter excludes non-approved stations: [`registry SQL:69`](../../../supabase/migrations/20261114120000_station_registry_drivers.sql#L69).
- **NEW route:** `POST /super-admin/stations/status`.
- **NEW** `app/Views/super-admin/live/stations.php`: copy existing registry markup and extend its Details dialog with status/reason confirmation. Reuse existing dialog JS.

**Tests:** **NEW** `supabase/tests/station-lifecycle.mjs`; extend `tests/registry.php` and `live-pages.php`. Cover unauthorized callers, stale/replayed requests, suspension/dispatch races, reconciliation, archived login denial and preserved customer history.

**Risks:** preserve Auth users, orders, payments, riders and audit history. Revoke only archived-station access; memberships at other stations remain valid. Do not expose operational blockers/counts to Super Admin.

## Coherent capacity model for features 1, 3 and 8

**Recommend whole-order trips plus an atomic daily refill allotment**, using existing `delivery_trips`/`trip_orders`.

| Quantity | Meaning |
|---|---|
| `total_units` | Packages in an order; existing package limit |
| `load_units` | Vehicle/container equivalents; existing product conversion |
| `batch_capacity_load_units` | Station ceiling per trip, initially 30 |
| `gallon_units_per_pack` | Product’s contribution to daily refill stock |
| `daily_gallon_limit` | Station’s declared daily refill allotment |
| Date override | Different available allotment for a particular Manila date |

Recommended unit interpretation: “30 gallons” means **30 standard refill containers**, subject to confirmation. Bottled boxes already use approximate transport equivalence, so load cannot represent literal water volume: [`manual box conversion:1`](../../../supabase/manual/2026-11-15-box-load-units.sql#L1).

Capacity behavior:

1. Both delivery and pickup reserve refill stock; pending reservation requests and unconfirmed duplicate holds do not.
2. Reserve on order commitment; mark consumed when preparation starts. Completed orders and prepared cancellations continue consuming that day’s allotment.
3. Release only unused reservations. Rescheduling transfers reservations atomically between dates.
4. Lock capacity dates in deterministic order, then recheck totals and order versions. Use one lock order across booking, preparation, cancellation and settings changes.
5. Reject reductions below already committed capacity. Backfill existing commitments from order snapshots/status history; flag ambiguous legacy records.
6. Do not invent a daily stock default. Require station configuration before enabling the new stock guarantee.

**Alternative:** prebook numbered trip slots. That is needed only if stations promise a fixed number of trips per date. It adds packing, time-slot and reassignment rules: three 20-container orders need three trips despite totaling two 30-container loads. Recommend deferring it; never equate `ceil(daily load / 30)` with guaranteed trips.

## Decisions and open questions

1. **What does “gallon” mean?** Default: one standard refill container. Literal water volume requires volume-based product conversions.
2. **What stock does the daily limit cover?** Default: refill/jug products, including pickup; separately packaged bottles use explicit classification. Full inventory accounting remains separate.
3. **Is there a fixed trip count per date?** Default: no; station sets a conservative daily allotment, with each dispatched trip capped separately.
4. **Cancellation policy?** Default: earlier of 15 minutes after commitment or preparation. Configure per station; snapshot per order. Confirm whether future scheduled orders should instead remain cancellable until shortly before service.
5. **Persistent login scope/duration?** Default: opt-in 30-day station-admin devices, revocable. Exclude customers, riders and Super Admin unless requested.
6. **Station removal?** Default: suspension followed by archival after reconciliation. Preserve customer history and owner Auth identity; deny the archived workspace.
7. **Rider email editing?** Default: edit station-local name/phone/vehicle/capacity/status; keep login email read-only pending a verified Auth flow.
8. **Routing provider?** Default for operational use: configured self-hosted or contracted OSRM-compatible service. Public OSRM is suitable only for a permitted demonstration: non-commercial use, at most one request/second, no uptime guarantee. [OSRM policy](https://github.com/Project-OSRM/osrm-backend/wiki/Demo-server) Reuse Leaflet/OSM tiles with visible attribution and normal caching; OSM forbids offline tile prefetch. [Tile policy](https://operations.osmfoundation.org/policies/tiles/) Without GPS, route from station/last valid fix and label it; without connectivity, retain the current address/stop list and manual arrival fallback.
9. **Registry privacy discrepancy?** The master plan specifies driver counts, while `get_registry_workspace()` currently returns driver names/phones: [`registry workspace SQL:12`](../../../supabase/migrations/20261121000300_registry_workspace.sql#L12). Default: do not expand this exposure; resolve counts-only alignment separately.

## Suggested milestones

| Milestone | Shippable result | Required checks |
|---|---|---|
| **M13** | Verify existing rename; complete rider editing | Registry, setup, operations PHP/SQL and live-view tests |
| **M14** | Station batch ceiling and booking fit checks | 30/31 boundaries, mixed loads, SSTF, duplicate dispatch |
| **M15** | Daily allotments and atomic reservations | Pickup, duplicate confirmation, reservation acceptance, rescheduling, stock consumption |
| **M16** | Configurable cancellation deadline | Time/status boundaries, races, capacity release, tracking |
| **M17** | Suspend/archive station workflow | Role isolation, reconciliation, history and login behavior |
| **M18** | Revocable trusted-device station sessions | Persistence, refresh serialization, revocation and outage recovery |
| **M19** | Rider road map and directions | Provider/GPS failures, throttling, SSTF preservation, phone field acceptance |

For each milestone: add behavior tests, implement the smallest change, run affected PHP scripts and PGlite suites, then preview/live DOM regression checks. Use `tests/support/fakes.php` and `supabase/tests/support/world.mjs`; no PHPUnit or new framework. Prove booking/dispatch races with two real PostgreSQL sessions in an isolated test environment—PGlite alone does not establish concurrent locking behavior.

Append M13–M19 to `docs/superpowers/plans/2026-11-01-hamtic-water-station-php-supabase.md`, recording accepted units, capacity/cancellation/session rules, migration order and acceptance cases. Update M11’s road-routing scope exclusion, retain SSTF’s role in stop ordering, and distinguish code completion from SQL Editor application and hosted verification.