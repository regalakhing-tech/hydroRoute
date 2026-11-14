# Phase 2 station setup implementation plan

> **For agentic workers:** Use superpowers:subagent-driven-development for the independent database, PHP and view tasks, followed by root integration and review. The approved design is `docs/prompts/2026-11-14-customer-station-owner-design.md`.

**Goal:** The owner saves profile/location, products, service areas, distance fees, order rules and schedules through checked database functions; approved public station availability reflects those rules.

**Architecture:** One actor-checked context RPC returns a bounded station setup projection. PHP services/controllers call RPCs with the current user token; server forms retain the existing appearance and previews. SQL transactions enforce scoped roles, versions, idempotency and availability changes. No remote SQL application or production deployment before the user-run migration checkpoint.

**Tech stack:** Existing PHP 8.5/Gateway, PostgreSQL/Supabase, PGlite, Tailwind 4, vanilla JavaScript, vendored Leaflet 1.9.4.

## Global constraints

- Preserve the three applied migrations and all existing live RPC signatures/result shapes.
- No secrets, direct remote SQL writes, real Auth-account mutations, real email, production deployment or Git push in local preparation.
- Every user mutation checks auth/role/membership, CSRF in PHP, same-station references, request UUID and expected version; money stays integer centavos.
- Super Admin remains denied transaction data, settings, chat and precise rider data even with an injected station membership. Public directory fields remain public.
- Keep `APP_PREVIEW=true` markup and behavior working. Never merge fixtures into a new live setup screen or use localStorage as a live save.
- Use the approved Hamtic bounds and the 47 official rows in `supabase/reference/hamtic-barangays.json`. Migration SQL is self-contained for SQL Editor.
- Task implementers edit only their owned files, do not stage/commit, and report red/green tests and concerns. Root integrates and records the checkpoint; the phase is not called complete until its migration is applied and verified.

## Shared RPC / view contract

All new RPCs return `{ok:bool,data:object,error_code:string|null,field_errors:object}`. Guard failures may raise `42501`; validation/conflict envelopes contain only controlled safe messages. Same request/payload replay returns the original committed data before checking a stale version; changed payload with a reused key conflicts.

`get_operation_context(p_station_id uuid)` returns an actor-checked object. For an own station admin, `data` has exactly these top-level projection keys:

```text
actor: {id, roles[]}
memberships: [{station_id, station_name, slug, role}]
station: {id,slug,name,barangay,barangay_id,municipality_code,address,phone,email,
          description,contact_name,latitude,longitude,profile_version,location_version}
settings: {version,max_order_units,max_distance_km,booking_days,duplicate_minutes,
           overdue_minutes,institution_bonus_enabled,urgency_asap,urgency_soon,
           urgency_later,urgency_immediate_minutes,urgency_soon_minutes,
           aging_interval_minutes,aging_points,aging_cap,institution_bonus,
           active_fee_version,algorithm_version}
barangays: [{id,psgc_code,name}]
service_areas: [{barangay_id,served}]
products: [{id,default_code,name,price_centavos,pack_text,description,image_key,
            returnable,load_units,active,version}]
opening_hours: [{id,weekday,window_no,opens_at,closes_at,order_cutoff,delivery_cutoff,enabled}]
closures: [{id,starts_at,ends_at,mode,public_notice,is_manual,cancelled_at,version}]
availability: {version,manual_mode,begins_at,expires_at,public_notice,is_open,
               accepting_asap,can_book,status_key,label,next_open_at,next_close_at,
               preview:[{date,windows:[{opens_at,closes_at,order_cutoff,delivery_cutoff}],notice}]}
fee_rules: {version,max_distance_km,bands:[{min_km,max_km,min_inclusive,max_inclusive,fee_centavos}]}
```

Array values are empty arrays, never null. Projection has no operational orders/counts/cash. Refuse an inaccessible station; do not return a different station silently. Null station may return only the caller's safe actor/membership choices; it never authorizes owner setup. `get_station_availability` and bulk `list_station_availability` return only public approved-directory fields, not internal reasons, affected order counts or private settings. Bulk result `data.stations` is an array of `{station_id,...public availability fields}`.

Physical field names in the projection are used in the new tables unless existing compatibility names differ. Times are `HH:MM:SS`, weekday is PostgreSQL 0=Sunday through 6=Saturday, instants ISO timestamps; seven-day preview is database-derived Manila time. Current public `available` maps from `accepting_asap`. Opening/closing is `[open,close)`; optional null cutoff means no earlier cutoff than closing. A provided cutoff must be inside the window. Closing now need not block a valid future request after known reopening; indefinite manual closure blocks all new bookings.

Use exact RPC names/types from the approved design. Additional Phase 2 credential guard is `authorize_driver_credentials(p_station_id uuid,p_driver_user_id uuid)` and returns `data.allowed`; implement this early so protected role mapping does not leave the existing global password-reset path unsafe. Directory count RPCs return `data.rows` with allowlisted station metadata/registered_count/active_count plus `data.unique_driver_count`; no individual driver rows.

Profile payload: `name,contact_name,phone,email,address,barangay_id,description`. Location has explicit numeric parameters. Products: `name,pack_text,description,image_key,price_centavos,returnable,load_units,active`; image keys only `water-jug`, `faucet-container`, `water-bottle`. PHP converts entered pesos to centavos without floating-point rounding. Four refill/jug defaults load=1, bottle boxes load=null until configured. Product `default_code` remains immutable seed metadata, never trusted from a browser.

Settings payload uses the exact `settings` projection field names except immutable version/active_fee_version/algorithm_version. Defaults: max_order_units20, max_distance_km10, booking_days7, duplicate_minutes30, overdue_minutes120, institution disabled, U40/25/10 with thresholds60/180, aging every10 minutes +5 cap25, institution10. Ordinary horizon can be 1–7 days; whole-order count is packages, separately from load units/empties.

Fee bands preserve inclusive boundaries: `[0,1)` 500; `[1,3]`1000; `(3,null)`1500. Validate arrays as a complete contiguous set, no gap/overlap, final open bound, nonnegative centavos. Rule sets are immutable; a change allocates the next locked version and updates active settings atomically.

Hours payload `p_windows` contains rows `{weekday,window_no,opens_at,closes_at,enabled}` and `p_cutoffs` rows `{weekday,window_no,order_cutoff,delivery_cutoff}`. Up to two windows per day, no overlap, blank/rest day represented by omitted/disabled windows. Both empty arrays are a valid closed-all-week schedule, with an explicit confirmation in UI. Seed existing legacy weekday/Sunday hours without inventing an earlier cutoff.

Closure interval payload is `{starts_at,ends_at,is_manual,closure_id?,closure_version?}`. A manual closure starts now or earlier and optionally expires; a dated closure has a valid finite interval. A dated edit must supply that closure's version as well as `p_expected_version` for station availability. `cancel_station_closure` checks target closure version and same-station authority; `reopen_station` clears manual overrides only. Scheduled closures remain effective. Reject overlapping dated closure entries; manual override can coexist with a dated closure. Availability changes lock the common row, audit and flag affected existing orders privately; no synthetic monetary history.

Expected versions: profile→profile_version; location→location_version; service areas/settings/fee rules→settings.version; hours/set closure/reopen→availability.version; cancel closure→closure.version; product edit→product.version (creation null id/version). Relevant mutations also increment availability.version to invalidate stale booking/dispatch views. Mutation data returns `{station_id,version,availability_version,product_id?}`; get a fresh projection on the redirect.

Live views receive `$data['setup']` containing the successful context data and `$data['setup_error']` for a safe unavailable state. Never read fixture fallback in these branches. UI forms use `Session::csrfToken()` and `StationSetup::requestKey()`; old submitted values are in `$data['setup_old']` with `_form` identifying the form. Controlled field errors may be in `$data['setup_errors']`. Escape all values.

POST routes and controller method names (root registers these):

```text
/station-admin/settings/profile        -> profile
/station-admin/settings/location       -> location
/station-admin/settings/service-areas  -> serviceAreas
/station-admin/settings/order-rules    -> settings
/station-admin/settings/delivery-fees  -> fees
/station-admin/products/save           -> product
/station-admin/schedules/hours         -> hours
/station-admin/schedules/closure       -> closure
/station-admin/schedules/closure/cancel-> cancelClosure
/station-admin/schedules/reopen        -> reopen
/station-admin/switch-station          -> switchStation
```

All live forms have hidden `_token`, `request_key`, `expected_version` plus `_form` matching controller method; product/closure IDs where applicable. Owner station ID comes from the checked session station, not a posted hidden station. Only switch-station accepts a target station intent and checks the protected context before changing the session. Each controller response uses existing 303 flash convention. No success on invalid/uncertain outcome. Failures log exception class only.

## Task 1: database and SQL behavior

**Own:** `supabase/migrations/20261114155714_station_setup.sql`, `supabase/tests/station-setup.mjs`, needed fixture/assertion adaptations in existing `supabase/tests/security.mjs` and `registry.mjs`. Do not edit the original three migrations, PHP, routes or views.

- [ ] Write PGlite behavior tests with test-only Auth roles/UID stubs. First test must fail on the empty new migration, e.g. assert the owner context/rules rows and seven default products exist after approval.
- [ ] Implement the approved Phase 2 tables, composite keys/indexes/RLS, safe role mapping backfill and unchanged-signature provisioning/approval initialization. No reset of owner prices or invented location. Keep ambiguous mapping fail-closed.
- [ ] Implement the above context/availability/count and all Phase 2 mutation RPCs with actor/request/version checks and private audit/closure flags. Restrict direct writes. Preserve the existing session RPCs. Public grants only for availability; own admin and platform count functions are authenticated with internal role checks; helpers private; service provisioning restricted.
- [ ] Exercise two stations/admins/customers, mixed roles, inactive membership, injected Super Admin membership, forged station/product/closure IDs, no direct workflow DML, invalid pins/phone/fee bands/windows, stale versions, replay and changed replay payload, seed idempotency, price history and closure/reopen/expiry/date boundaries. Test RLS through actual SET ROLE.
- [ ] Run `node supabase/tests/station-setup.mjs`, `node supabase/tests/security.mjs`, `node supabase/tests/registry.mjs`. Report exact counts and any hosted/concurrency limits to `storage/phase2-db-report.md`.

## Task 2: PHP service/controller layer

**Own:** new `app/Services/StationContext.php`, `app/Services/StationSetup.php`, `app/Controllers/StationSettingsController.php`, `tests/station-setup.php`. Root owns route registration/UIController/StationDirectory/Registry integration and legacy StationController guard.

**Interfaces:** `StationContext(Gateway $db)::load(string $token, ?string $stationId): array` memoizes a checked successful context per token/station within the object; safe failures throw RuntimeException. `StationSetup(Gateway $db, ?StationContext $context=null)::load(string $token,string $stationId): array`; `StationSetup::requestKey(): string` creates UUIDv4; mutation wrapper with explicit operation allowlist; public service methods may wrap individual operations. `StationSettingsController(StationSetup $setup,CurrentUser $current)` exposes the above method names.

- [ ] Write FakeGateway tests rejecting role/CSRF/missing token/invalid UUID/money/coordinate/array parameters before mutation; assert user token and derived session station in each RPC; parameter names and version mapping match contract.
- [ ] Implement request-key validation/replay preservation, decimal-to-centavos parsing, per-form input/array parsing, safe error-code mapping and old input/errors. Never let browser station/actor/role/computed totals authorize a change.
- [ ] Assert successful redirect/flash, mutation field errors, forbidden/stale/network outcomes, product deactivate/creation, hours/closure/rest days/reopen, and safe station switching with correct name/slug update. Existing Auth/driver functions must not change.
- [ ] Run `php tests/station-setup.php` and existing auth/registry/session/catalogue unit scripts; report to `storage/phase2-php-report.md`.

## Task 3: live owner views and progressive enhancement

**Own:** existing five station views `settings-profile.php`, `settings-service-areas.php`, `settings-order-rules.php`, `products.php`, `schedules.php`; new `app/Views/station-admin/setup/` partials, `public/assets/js/station-setup.js`, `tests/station-setup-dom.mjs` and focused PHP fixture rendering helper in `tests/support/` if needed. Root owns head/layout/core CSP/assets/route loader and vendors Leaflet.

- [ ] Write view/DOM tests using a fake successful setup projection. Test each real form action/method/CSRF/key/version, real data/empty/failure states, escaped names/notices, monetary fields, product selection and preserved old input. Prove preview fixtures still use original branch.
- [ ] Live branch activates only when `!$data['preview']`; either render `$data['setup']` or a safe unavailable state, never fall through to the original sample template. Preserve the visual design/mobile components.
- [ ] Provide profile fields and manual/map/device pin form; all 47 served-area choices; actual rules and editable versioned fee bands; product list/add/edit/deactivate/full metadata/load conversions; two weekly windows/rest days and optional cutoffs; dated/manual closure/cancel/reopen; next-seven-bookable-days preview. Leave actual mutations to forms; no localStorage success.
- [ ] JS uses existing deferred scripts and `window.L` (root supplies vendored `/assets/vendor/leaflet/leaflet.js` and CSS) for a pin picker; OSM tile URL exactly `https://tile.openstreetmap.org/{z}/{x}/{y}.png` and attribution. Keep manual inputs usable without JS or GPS permission. Dynamic field add/remove and money previews never authorize values.
- [ ] Test no fake identity/date/price/closures in live projections; safe empty products/areas/windows; disabled location/out-of-bounds feedback; input constraints; normal form fallback. Run `node tests/station-setup-dom.mjs` plus appropriate preview suites once root server is available. Report to `storage/phase2-ui-report.md`.

## Task 4: root integration, security review and migration handoff

**Own:** `routes/web.php`, `UIController`, `StationDirectory`, `Registry`, legacy StationController credential check, shared head/layout, `Response` CSP, `public/index.php` asset allowlist, vendored Leaflet files/license, compiled CSS, progress/handoff docs and integration tests.

- [ ] Wire one shared StationContext/StationSetup and the new controller to exact routes. Load complete real setup for five owner pages. Revalidate membership for live owner pages, fail closed on absent migration; no fake success or unsafe alternate station. Safe authorized station picker if multiple memberships.
- [ ] Directory/owner availability uses the DB availability function, preserving public station shape/empty/error behavior and preview fixtures. Safe registry uses driver counts without individual rows, maintaining registry approval actions. Add legacy driver-password authorization guard.
- [ ] Vendor pinned stable Leaflet/license locally, add exact asset types, load only on setup/map screens, widen only CSP image host (scripts stay self). Build CSS after new classes.
- [ ] Run phase SQL/PHP/DOM tests, all existing PHP/RLS/preview suites and private-file boundary checks. Review full phase diff including all grants and security-definer functions; resolve Important/Critical findings and rerun affected tests.
- [ ] Prepare a standalone read-only preflight/verification SQL file and exact migration handoff, recording any ambiguous legacy mappings. Stop for user SQL Editor execution/confirmation; do not start Phase 3 or deploy dependent code before the phase's migration is applied/verified and tests pass. Keep local progress accurate.
