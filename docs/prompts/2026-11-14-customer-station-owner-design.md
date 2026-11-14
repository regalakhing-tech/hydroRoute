# HydroRoute customer and station-owner implementation design

Prepared 14 November 2026, Asia/Manila. **Status: approved by the user's "continue all" instruction.** Phase 2 local implementation may proceed; each remote migration still follows the user's SQL Editor application checkpoint. The page/action checklist and test evidence are in [progress.md](progress.md).

## What this will deliver

A customer saves an address, chooses a station's actual products, places one delivery or pickup order, sees its saved history and status, and exchanges messages and reservation requests with that station. That station's owner sees the same order and conversation, prepares the order, dispatches an eligible rider or records pickup, verifies collection, and sees accurate reports. Reloading or reaching another Vercel instance preserves the result. Another station and Super Admin cannot obtain those operational records.

Implementation follows the nine phases in [the requested prompt](2026-11-14-build-customer-and-station-owner.md). The [system plan](../superpowers/plans/2026-11-01-hamtic-water-station-php-supabase.md) governs business behavior. Its explicit corrections supersede the original blueprint. Existing physical tables and working RPC signatures remain in use; logical names in the older plan are not instructions to recreate them.

## Review decisions

These are concrete recommendations for approval, not rules already implemented. Approving this document approves these interpretations; it does not authorize automatic application of migrations. The prompt separately requires each migration to be run by the user in Supabase's SQL Editor.

| ID | Recommended rule | Reason / source conflict |
| --- | --- | --- |
| D1 | Calculate fees and the 10 km eligibility boundary from unrounded Haversine distance. Save that distance and display a rounded 0.1 km estimate. | System-plan M11 says round to 0.1 km, but acceptance section 13 requires 0.99 km to cost PHP 5 and 3.01 km PHP 15. Rounding before choosing a band fails those examples. |
| D2 | On arrival, automatically lower the fee when a valid measurement gives a lower band. A higher band keeps the quoted charge and opens an owner review. Review records an explanation; it cannot create a surcharge. Denied GPS or accuracy above 100 m keeps the quote. | M11 explicitly asks for station confirmation of the lower/higher-fee rules. Exactly 100 m remains acceptable. No new refund, credit or customer-consent surcharge workflow is introduced. |
| D3 | Require a protected role **at the specific station**, in addition to membership. Preserve existing single-station driver creation and credential controls, but prohibit an owner from resetting a shared or administrator identity's global password. Such users set their own password through an emailed setup/recovery flow. | Current global roles plus roleless station memberships can make an admin at A a false admin at B. A global password reset of a mixed-role/shared account could also cross that boundary. Ambiguous existing assignments require operator-reviewed mapping. |
| D4 | Keep same-day future-time orders `confirmed`; future-day orders are `scheduled` and release at a valid opening on their service day. Accepted reservations begin `scheduled` and may release on the next eligible job even on the current day. | This follows system-plan sections 8–9 and M12 instead of the prompt's broader phrase "future time". No early dispatch before the 60-minute due window. |
| D5 | Only the customer confirms an intentional duplicate. An owner can contact the customer and use the narrowly allowed cancellation paths, but cannot consent to a repeat for them. | System-plan `confirm_duplicate` is owner-of-order only; prompt Phase 5 also mentions admin confirmation. The system plan takes precedence. |
| D6 | Reservations may be enquiries for a later date or larger quantity. Acceptance rechecks the current normal booking/quantity rules and actual vehicle feasibility; it does not silently override them. An early request can stay requested until bookable, or the customer can submit a revised request after discussion. | M12's exceptional-request examples conflict with its "never bypasses validation, capacity limits, or closures" rule. The proposed first release keeps those hard limits. A separate exceptional-order policy would need separate approval. |
| D7 | The whole-order limit counts purchased units/packages. Returnable-container counts are separate. Each of the four refill/jug products has one load unit per purchased unit. Bottled-water boxes require the owner to configure a positive load-unit conversion before a delivery quote/order is accepted; pickup remains available. | Boxes are not automatically equivalent to a gallon/container. No invented box weight or universal 15-unit vehicle capacity. Drivers retain their configured capacity and products save load snapshots. |
| D8 | Use the proposed Hamtic validation envelope: latitude 10.584854–10.746484, longitude 121.961241–122.073730, with an official Hamtic barangay selection. | Derived by rounding outward from the pinned geometry described below. A rectangle is a coarse application check, not proof of a legal municipal boundary. Station registration review remains required. |
| D9 | Allow owner cancellation of an unfulfillable `ready` order only with a reason, no active trip assignment and no applied payment. Require full cash for a successful delivery/pickup result in this release. | The system plan calls the ready-cancellation path proposed. Underpayment exceptions are not specified sufficiently to introduce credit, refunds or arbitrary write-offs. |
| D10 | Service windows use `[opening, closing)`. Cutoff belongs to a specific service window and must be earlier than closing; the cutoff instant is accepted. Today's passed cutoff does not prevent booking a valid future service day. | Makes split windows, exact closing times and future bookings consistent. No undocumented preparation lead time. |
| D11 | Sequence SSTF using Haversine distance quantized to 0.000001 km solely for deterministic ties; tie by higher score then order UUID. Preserve any already lowered fee across reattempts until collection; final fee never rises above the original quote. | The plan does not specify numerical tie precision or a fee increase after a failed attempt. Fee-band comparisons still use D1's unrounded value. |
| D12 | Tighten Super Admin driver visibility to registration counts and allowlisted directory data. Keep its application/approval routes working and preserve their RPC signatures. | The existing `drivers_registry_read` policy returns complete driver rows; the canonical privacy matrix says counts only. This is a coordinated policy, registry query and UI change, not permission expansion. |

Defaults already supplied by the prompt remain: fees 500 / 1,000 / 1,500 centavos; maximum distance 10 km; 20 purchased units per ordinary order; seven local calendar days ahead; 30-minute duplicate window; institution bonus off; overdue alert after 120 waiting minutes; seven catalogue defaults. These are station records, not PHP pricing constants.

## Application boundaries

Keep PHP 8.5, Supabase Auth/PostgREST, Tailwind 4, vanilla JavaScript and SweetAlert2. Reuse the current page design and components. Separate complete live presenters from preview fixtures: `APP_PREVIEW=false` never invokes or merges `CustomerData`, `StationData`, `RiderData` or `MessagingData` into a customer/owner operational screen. A failed query produces an honest empty/error state, never fixture fallback.

| Boundary | Responsibility and principal files |
| --- | --- |
| Authentication/context | Extend `CurrentUser` and add `StationContext`; refresh the user token, check session revocation, load protected station-role context once per request, and support explicit authorized station selection. `Controller::member` remains a page guard; database functions recheck authority. |
| Station setup | `StationSettingsController`, `StationSetup` service, `StationAvailability` service; profile/pin, service areas, products, settings, fee versions, hours/closures/reopen. Extend `StationDirectory` to use database availability and a public catalogue projection. |
| Customer records | `CustomerController`, `CustomerRecords` service; own profile, preferences, address CRUD/default, account password and global logout. Verified Auth email is displayed read-only instead of presenting a nonfunctional email-change form. |
| Orders | `OrderController`, `Orders` service, `CustomerWorkspace` and `StationWorkspace` presenters; quote, submission, duplicate decision, cancellation, reschedule, orders and timeline. Database owns pricing and state transitions. |
| Operations | `StationOperationsController`, `StationOperations` service; queues, reviews, preparation, readiness, customer classification and pickup collection. |
| Dispatch/rider | `DispatchController`, `Dispatch` service, `RiderController`, `Deliveries` service; preview/dispatch, SSTF, assignments, arrival, result, cash, return and history. Existing `StationController` driver provisioning remains a distinct trusted account operation. |
| Messaging | `MessageController`, `Messaging` service and a live shared message presenter; inbox, message sends, receipt and unread counts. Preview messaging retains its existing behavior in an explicit preview branch. |
| Reservations | `ReservationController`, `Reservations` service; request/cancel and owner response with one atomic scheduled-order creation. |
| Notifications/reports | `NotificationController`/`Notifications` and `ReportController`/`Reports`; scoped reads, receipts, date filters, CSV and print. |

Services depend on `Gateway` and use `rpc(..., $token)` for ordinary operations. No browser access/refresh tokens and no service-key user workflows. Existing secret-key provisioning and session storage remain server-only. The HTTP transport retains the verified older-libcurl fallback and TLS verification.

Every new POST has server CSRF validation and usable HTML form fields, method and action. Successful mutations use server-confirmed flash plus 303 redirect. Invalid forms preserve nonsecret values and show field errors. HTML escapes all data; JavaScript inserts message bodies with `textContent`. The response envelope is `{ok,data,error_code,field_errors}`; map input errors to 422, authentication to 401, forbidden/not-found to 403/404, state conflicts to 409. An uncertain network write is retried with the original request key.

Existing preview role explorer, sample footers, fake identities, local draft/status hooks and fixture chat seeds are preview-only. Live station switching lists only the caller's authorized memberships; selecting a station never grants access. No live workflow claims success from localStorage.

## Database extension and preservation

Do not edit the three applied migration files. Generate each new migration through the installed Supabase CLI's `migration new` command, after checking its current help. Write later phase changes as additive migrations. The user applies them through SQL Editor; the agent uses read-only verification afterwards.

Keep existing `private.user_roles`, `private.station_memberships`, `profiles`, `stations`, `addresses`, `products`, `orders`, `order_items`, `conversations`, `messages`, `read_receipts`, `station_registrations`, `drivers` and `app_sessions`. No duplicate `users`, password table, `customer_addresses` or `station_registration_requests` tables. No deletion or invented reconstruction of historical orders or financial records.

UUID entity IDs; `timestamptz` instants; `date` Manila service dates; `time` weekly windows; positive integer quantities; integer unit-price centavos and bigint aggregate centavos. Currency never uses floating point. Coordinate pairs are both null or valid; unavailable old coordinates are not backfilled with zero.

Every new station-scoped parent has a unique `(id,station_id)` reference key in addition to its UUID primary key. Where a child must also match the same order/customer, use the corresponding composite triple, including `(payment_id,station_id,order_id)` and `(attempt_id,station_id,order_id)` for collection reconciliation. Index the referencing columns. A UUID alone does not prove a valid station/customer/order relationship.

### Phase 2: authorization and owner setup

| Object | Fields / constraints / purpose |
| --- | --- |
| `private.station_membership_roles` (new) | `(station_id,user_id,role)` primary key, role `station_admin` or `rider`, active, actor/time/version; composite FK to existing membership. Index `(user_id,role,active,station_id)`. Existing global roles route logins; this mapping authorizes a station role. |
| `stations` (extend) | Description, public contact name, Hamtic municipality code, optional official barangay FK, latitude/longitude, profile/location versions, updated time. Preserve current name/contact/address and legacy hour fields until all readers use availability RPCs. No operational counts or financial columns. |
| `barangays` (new) | UUID, unique ten-digit PSGC code, municipality code, official name, active. Only Hamtic reference rows; source/version recorded. Public read, no ordinary writes. |
| `station_service_areas` (new) | `(station_id,barangay_id)` primary key, served, actor/time. Version changes through the station settings lock. No per-barangay fee/minimum order policy; replace those preview-only controls with served/not served selection. |
| `station_settings` (new) | Station primary key; whole-order limit, maximum distance, booking horizon, duplicate/overdue intervals, institution switch, U thresholds/points, aging interval/increment/cap, institution points, active fee version, algorithm version and optimistic settings version. Store the prompt's exact defaults. |
| `delivery_fee_rule_sets` and `delivery_fee_rules` (new) | Rule-set primary key `(station_id,version)` with effective time/creator/max distance/algorithm version. Bands primary key `(station_id,version,band_index)`, distance bounds, inclusive/exclusive flags and nonnegative centavos. Validate the entire set for continuity, no overlap/gaps and D1 boundaries. Referenced versions are immutable. |
| `station_opening_hours` (new) | UUID, station, weekday 0–6, window number 1–2, opens/closes, order/delivery cutoff, enabled. Unique station/weekday/window; valid nonoverlapping windows, cutoff inside the window. Overnight hours split across adjacent days. |
| `station_closures` (new) | UUID, station, start/end, mode, internal reason/public notice, actor, cancellation metadata and version. Bounded planned intervals; an immediate manual override may be indefinite. Index station/start/end. |
| `station_availability` (new) | Station primary key, manual mode/start/optional expiry/public notice, version. Shared lock/version row for bookings, schedules, closure, fees/location changes and dispatch. Effective availability is evaluated from current database time, not a stale cached label. |
| `products` (extend) | Optional stable default code, pack text, description, allowlisted image key, returnable flag, positive optional load units, version/update time. Unique `(station_id,default_code)` when present. Deactivate used products; retain existing names/prices and all order references. |
| `station_audit_events` (new) | UUID, station, actor or system-job identity, action/entity, reason, time, request key and allowlisted before/after values. Append-only, owner read only. No tokens or complete message/session payloads. |
| `private.mutation_requests` (new) | Actor, nonnullable scope key, operation, request UUID, normalized-payload digest, committed JSON result/time; primary key actor/scope/operation/request. Station scope is explicit; global own-profile/address operations use a user scope. No nullable uniqueness holes or ordinary reads/writes. |
| `private.station_counters` (new) | Station primary key, next order number. Allocate under row lock, never `count()+1` or unlocked `max()+1`. |

Seed missing settings, fee versions and seven default products for every approved station. Add the same initialization inside the existing approval RPC without changing its signature or returned registry fields. Stable codes make seeding repeatable. Existing configured prices are never reset. Any ambiguous legacy product/role/hour mapping is shown in a read-only preflight and requires an approved mapping before backfill.

Backfill trusted single-role memberships and existing driver-station links only when unambiguous. Do not infer admin rights from a mixed global role list. Strengthen `private.station_admin(uuid)` and provisioning internals without changing signatures. Membership checks permit authorized settlement of existing work during suspension; separate eligibility checks reject new business.

Closure workflows need review flags and notification/audit infrastructure before ordering goes live. Create their minimal common tables in Phase 2 where necessary, then extend them in the phase owning their full behavior. This avoids a Phase 2 closure RPC referring to absent Phase 4 objects. Legacy orders receive explicit review flags, not invented monetary snapshots or fictional history.

### Phase 3: own profile, addresses and catalogue

| Object | Fields / constraints / purpose |
| --- | --- |
| `profiles` (extend) | Normalized phone, active, created/update time, version, validated notification preferences `order_updates`/`station_updates`. Role/classification/station membership are never editable here. Existing names/contact remain ordinary identity data. |
| `addresses` (extend) | Official barangay, street/purok, landmark, coordinate pair, `pin_source` map/device/manual, default, soft-delete time, version/update time. Preserve `(id,customer_id)` and text field. One nondeleted default per customer using a partial unique index; serialize default changes on the customer row. |
| `private.user_session_state` (new) | User primary key and revocation epoch. A global logout invalidates every PHP session at the next request; missing legacy epoch is treated as zero, not auto-promoted. Auth global sign-out also revokes refresh sessions. |
| `app_sessions` (compatible extension only) | Preserve cookie hashing, serialized format and existing session RPC signatures. Add nullable user UUID, integer version, nullable refresh-lease token/expiry. Add server-only v2 read/write and refresh-lease RPCs. Conditional writes prevent a stale request from overwriting rotated tokens. No user-readable session table or exposed tokens. |

Addresses without pins may be saved; they are ineligible for delivery. Pickup requires no delivery address. Soft-deleting an address preserves the existing FK and every order snapshot. Station owners and riders get necessary order/stop contact snapshots, not access to a customer's mutable saved-address directory. Retire the existing broad `addresses_station_read` policy after those consumers are migrated.

Customer account email becomes read-only Auth email; display name/mobile are saved through a user RPC. Password change uses Supabase Auth with current-user confirmation. Notification preferences affect optional delivery of notices; durable operational events and mandatory order history are still written.

### Phase 4: orders and customer tracking

| Object | Fields / constraints / purpose |
| --- | --- |
| `orders` (extend) | Station order number; request key, optimistic/contract version; scheduling mode/requested instant/service date/queued/released/completed times; quantity/load/expected empties/note; contact, address, barangay, pin/source and station-pin snapshots; item subtotal; raw/display quoted distance; quoted/final fee, fee-rule version and total centavos; duplicate-original/fingerprint/override; closure-review and redispatch-pause flags/reasons; U/A/C/P, score time and algorithm version. |
| `order_items` (extend) | Keep current product/name/unit-price/generated bigint line total and composite FKs. Add pack, returnable and load-unit snapshots. Prices and sums are set by the database. |
| `order_status_logs` (new/common) | UUID, station/order, from/to, actor/job identity, reason, instant, order version/request key; composite order FK and one actual transition per order version. Index `(station_id,order_id,created_at,id)`. Non-status edits are audit events, not fabricated transitions. |
| `notifications` (new/common) | UUID, station, intended recipient, event/type, safe parent order/conversation/reservation reference, rendering data, read/time/channel. Unique event/recipient/channel. Recipient-only reads; no Super Admin recipient for operational notices. |
| `private.notification_outbox` (new) | Optional email work, attempts/backoff/lease/result, event/recipient deduplication. Provider calls happen after commit. Email failure never rolls back an order or sends a duplicate event. |

Retain all current valid order statuses, including legacy `pending`, while extending the existing fulfillment/status check for `on_hold`, `scheduled` and `completed`. New successful submission never ends at `pending`. `returned` and `failed` are validation/attempt outcomes, not new order statuses. Delivery cannot use `collected`; pickup cannot have address/driver/trip/delivery fee or enter `out_for_delivery`/`delivered`.

Unique `(station_id,customer_id,request_key)` for new submissions and `(station_id,order_no)` for public numbers. Composite references keep duplicate original, fee rule, items and parent objects inside the same station/customer scope. Legacy rows keep an explicit older contract version and nullable unknown snapshots; a migration must not invent their historical prices, pins or fees. New RPC-created rows require complete valid snapshots and totals.

Indexes: `(station_id,status,service_date)`, `(station_id,customer_id,created_at,id)`, `(station_id,fingerprint,created_at)`, scheduled release and eligible-queue partial indexes, every child FK, notification recipient/read/time cursor. Do not treat a stored priority score as perpetually current.

### Phases 5–6: owner operations, trips and rider results

| Object | Fields / constraints / purpose |
| --- | --- |
| `customer_station_profiles` | Primary key station/customer, household/institution classification, service restriction/reason, actor/time/version. Owner may classify customers who actually ordered at that station; no global identity edits. |
| `riders` | Additive operational rider record: UUID, station/user, vehicle/plate/capacity, enabled and version; unique station/user and id/station; protected rider-role membership. Existing `drivers` remains the compatible registration table and is not repurposed for public trip state. Seed existing rows, and extend the unchanged `register_driver` internals to atomically insert the operational rider and role mapping on every subsequent driver creation. Additional memberships use a distinct provisioning operation. |
| `private.rider_reservations` | User primary key, active trip/station and version. A human rider is reserved across all station memberships until authorized return/closure. |
| `delivery_trips` | UUID, station, rider/user, capacity/load snapshots, active/returned/closed state, dispatch/return/close times, route/version/request key and traveled distance. Same-station rider FK, load ≤ capacity, unique active trip per rider user. |
| `trip_orders` | UUID stop, station/trip/order, SSTF sequence/leg distance/priority snapshot, manual-front reason, active assignment, pending/arrived/delivered/failed stop state and version. Composite parent FKs, one active assignment per order, unique trip/sequence. Keep prior failed assignments. |
| `rider_locations` | UUID, station/trip/rider, finite coordinates, nonnegative accuracy, database recording time, optional captured time and request key. Active assigned trip only; indexes trip/time/id and retention time; deduplicate request keys. |
| `delivery_attempts` | UUID, station/stop/order, attempt number/state, failure reason, empties, arrival coordinates/accuracy/fallback, raw measured distance/fee, fee decision, arrival/result time, actor/request keys/version. One resolution per stop. Arrival can create an open attempt; result finalizes it. |
| `payments` | UUID, station/order/attempt (nullable for pickup), applied/tendered/change centavos, collection actor/time, unverified/verified/resolution-required state, verification actor/time/version/request key. Nonnegative amounts; tendered − change = applied; one collection per successful attempt or pickup. No silent financial overwrites. |
| `order_collections` | UUID, station/order, optional successful delivery attempt, payment, expected-empties snapshot, actual empties, collection actor/time, unverified/verified/resolution-required state, verification actor/time, discrepancy reason and version. Unique order and payment; composite same-station FKs. Pickup has no attempt. Both delivery and pickup collection RPCs insert this row atomically with cash/result; completion requires verified cash and container reconciliation. No inventory, deposit or credit ledger is implied. |
| `order_reviews` | UUID, station/order, overdue/closure/capacity/fee-mismatch/failed-attempt kind, open/resolved, acknowledgement/resolution actor/time/reason/version. Partial unique open review of a given kind per order. |

Keep `orders.driver_id` compatible with the assigned Auth UUID; trip/stop relationships are authoritative. Riders read only assigned trip/stop contact projections, own results/collections and own history. Customers see driver name/contact on their order, not live rider coordinates. Owner trip pages may show their own station's rider positions.

`riders` is authoritative for operational vehicle/capacity/enabled changes; availability derives from the protected active-trip reservation, never a manually editable "free" flag. `save_rider` also synchronizes compatibility registration fields only when the existing `drivers` row belongs to the same station, leaving another station's registration untouched. Existing owner-created drivers therefore enter dispatch without another backfill. A shared rider's global busy check discloses availability only, not another station's name, trip ID or trip counts.

Expected returns derive from saved returnable item quantities, independent of vehicle load units. Actual empty counts are nonnegative integers; a difference from expected creates a reasoned owner review before completion. `verify_payment` verifies the payment and its `order_collections` row under their versions in one transaction; it cannot silently rewrite the rider's original count. Collection corrections append an audited reason and before/after values. No extra fee or debt is created for missing empties by implication.

### Phase 7: messages and reservations

| Object | Fields / constraints / purpose |
| --- | --- |
| `conversations` (extend) | Preserve one conversation per station/customer and existing linked order; add version/last activity and unique id/station/customer for child relationships. |
| `messages` (extend) | Derive station/customer from conversation; add kind text/reservation/system, topic general/reservation/order/delivery, optional same-station/customer order/reservation link and request key. Keep length 1–2,000 with nonblank body and escaped plain text. System events use explicit system actor semantics, not a fake Auth user. |
| `read_receipts` (extend) | Derived station, last visible message cursor and read boundary. Advance monotonically only to acknowledged visible messages. Adapt the current timestamp trigger so it cannot mark unseen messages read merely by setting `now()`. |
| `reservations` | UUID, station/customer/conversation, requested time, fulfillment, owned address if delivery, note, requested/accepted/declined/cancelled/expired state, decision reason/actor/time, created order, version/request key. Composite parent ownership; at most one order per reservation. |
| `reservation_items` | UUID, station/reservation/product, positive quantity; unique reservation/product; same-station FKs. No price chosen by customer; acceptance prices current active products. |
| `private.message_rate_limits` | Sender UUID primary key and update time; locks sender-wide accounting across all conversations/stations. Under that lock count the sender's saved messages in the preceding rolling 60 seconds using `(sender_id,created_at,id)` index. At most 20 new messages; retries of a committed request do not consume another quota entry. |

Revoke existing direct message and receipt DML when their RPCs are ready. Clients cannot bypass rate limits, notifications, idempotency or sender/timestamp attribution. Rider and Super Admin identities remain denied chat/reservations, including accidental mixed privileges. Same-station admins are the station participants; the sender remains the individual actor for audit.

### Phase 8: reports and jobs

`private.station_job_runs` uses primary key `(station_id,job_type,scheduled_slot)` with a timestamptz slot, start/finish times, status, safe error class and bounded-batch checkpoint. A restricted minute job releases eligible schedules, handles closure/missed-time reviews, expires unanswered reservations and enforces location retention; a five-minute slot recomputes queue scores. Never wait for Cron to determine whether the station is actually open. Job outputs remain station-private.

Delete raw rider/arrival coordinates after 30 days; retain measured attempt distance, accuracy, fee decision and business result. Preserve order address snapshots. No tracking outside active trips. Optional email work has independent retry state and deduplication.

Reports are scoped invoker queries or actor-checked RPCs, never publicly elevated views. Manila date ranges are `[start midnight, midnight after end date)`. Show completion-date gross order totals, collection-date applied cash, verification-date verified cash, outstanding unverified collection, and delivery fees as separate measures. Orders report by status/fulfillment/product/day; delivery report by trips/stops/outcomes/distance/elapsed time. State the date basis in the UI. CSV uses the same scoped query/filters and protects formula cells; print uses a dedicated stylesheet. Do not sum collection and sales as if they were separate revenue.

## Exact proposed RPC interface

Freeze these `p_` names/types for PostgREST; no overloaded variants. Every new listed function returns JSONB with the envelope above. Nullable fields are still supplied explicitly by PHP; no reliance on ambiguous default overloads. `int` means PostgreSQL integer; entity IDs/request keys are UUID. Numeric coordinates/distances are not currency. All authenticated operations also check the appropriate protected application role internally.

| Phase / allowed actor | Function signature |
| --- | --- |
| 2 operational actor | `get_operation_context(p_station_id uuid)` |
| 2 public approved directory | `get_station_availability(p_station_id uuid, p_requested_at timestamptz)`; `list_station_availability(p_station_ids uuid[], p_requested_at timestamptz)` |
| 2 own owner | `save_station_profile(p_station_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid)` |
| 2 own owner | `save_station_location(p_station_id uuid, p_latitude numeric, p_longitude numeric, p_expected_version int, p_request_key uuid)` |
| 2 own owner | `save_station_service_areas(p_station_id uuid, p_barangay_ids uuid[], p_expected_version int, p_request_key uuid)` |
| 2 own owner | `save_station_settings(p_station_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid)` |
| 2 own owner | `save_delivery_fee_rules(p_station_id uuid, p_bands jsonb, p_max_km numeric, p_expected_version int, p_request_key uuid)` |
| 2 own owner | `save_product(p_station_id uuid, p_product_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid)`; null id/version creates a product |
| 2 own owner | `save_station_hours(p_station_id uuid, p_windows jsonb, p_cutoffs jsonb, p_expected_version int, p_request_key uuid)` |
| 2 own owner | `set_station_closure(p_station_id uuid, p_interval jsonb, p_mode text, p_public_notice text, p_expected_version int, p_request_key uuid)` |
| 2 own owner | `cancel_station_closure(p_closure_id uuid, p_reason text, p_expected_version int, p_request_key uuid)`; `reopen_station(p_station_id uuid, p_expected_version int, p_request_key uuid)` |
| 2 Super Admin | `get_platform_station_registry(p_filters jsonb)`; `get_driver_registration_counts(p_filters jsonb)`; directory fields/counts only |
| 3 own customer | `save_customer_profile(p_payload jsonb, p_expected_version int, p_request_key uuid)`; allowlist display name, phone and the two preference booleans |
| 3 own customer | `save_customer_address(p_address_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid)`; null id/version creates an address |
| 3 own customer | `delete_customer_address(p_address_id uuid, p_expected_version int, p_request_key uuid)`; `set_default_address(p_address_id uuid, p_expected_version int, p_request_key uuid)` |
| 3 own operational actor | `revoke_own_app_sessions(p_request_key uuid)`; Supabase Auth global sign-out is also called by PHP |
| 3 service-only session handler | `session_read_v2(p_id text)`; `session_write_v2(p_id text, p_data text, p_ttl int, p_expected_version int, p_user_id uuid)` |
| 3 service-only refresh serialization | `session_acquire_refresh(p_id text, p_expected_version int, p_lease_token uuid)`; `session_release_refresh(p_id text, p_lease_token uuid, p_data text, p_ttl int, p_expected_version int)` |
| 3 own customer quote | `quote_delivery(p_station_id uuid, p_address_id uuid)`; `quote_order(p_station_id uuid, p_payload jsonb)` |
| 3 own customer overview | `get_customer_overview()`; returns actual/empty order, message and notification projections before submission is enabled in Phase 4 |
| 4 own customer read | `list_customer_orders(p_filters jsonb, p_cursor jsonb)`; `get_customer_order(p_order_id uuid)` |
| 4 customer | `submit_order(p_station_id uuid, p_payload jsonb, p_request_key uuid)` |
| 4 customer owner | `confirm_duplicate(p_order_id uuid, p_expected_version int, p_request_key uuid)` |
| 4 customer / allowed owner exception | `cancel_order(p_order_id uuid, p_expected_version int, p_reason text, p_request_key uuid)`; `reschedule_order(p_order_id uuid, p_requested_at timestamptz, p_expected_version int, p_request_key uuid)` |
| 3 intended recipient counts | `get_navigation_counts(p_station_id uuid)`; null station derives own customer scope |
| 4 intended recipient list | `list_notifications(p_station_id uuid, p_cursor jsonb)` |
| 4 intended recipient | `mark_notification_read(p_notification_id uuid, p_request_key uuid)`; `mark_all_notifications_read(p_cutoff timestamptz, p_request_key uuid)` |
| 5 own owner | `get_station_overview(p_station_id uuid)`; `get_preparation_queue(p_station_id uuid)`; `get_dispatch_queue(p_station_id uuid)`; `get_station_order(p_order_id uuid)` |
| 5 own owner | `start_preparation(p_order_id uuid, p_expected_version int, p_request_key uuid)`; `mark_order_ready(p_order_id uuid, p_expected_version int, p_request_key uuid)` |
| 5 own owner | `get_station_customers(p_station_id uuid, p_filters jsonb, p_cursor jsonb)`; `set_customer_classification(p_station_id uuid, p_customer_id uuid, p_classification text, p_expected_version int, p_request_key uuid)` |
| 5 own owner | `resolve_order_review(p_review_id uuid, p_action text, p_reason text, p_expected_version int, p_request_key uuid)` |
| 5 own owner | `preview_trip(p_station_id uuid)`; `dispatch_trip(p_station_id uuid, p_rider_id uuid, p_order_ids uuid[], p_request_key uuid, p_proposal jsonb)` |
| 5 own owner | `record_pickup_collection(p_order_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid)`; `verify_payment(p_payment_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid)` |
| 5 own owner | `review_delivery_fee(p_order_id uuid, p_decision text, p_reason text, p_expected_version int, p_request_key uuid)`; `complete_order(p_order_id uuid, p_expected_version int, p_request_key uuid)` |
| 5 own owner | `approve_reattempt(p_order_id uuid, p_expected_version int, p_reason text, p_request_key uuid)` |
| 5 own owner, credential eligibility only | `authorize_driver_credentials(p_station_id uuid, p_driver_user_id uuid)`; returns no other-station membership/contact data |
| 5 own owner | `save_rider(p_rider_id uuid, p_payload jsonb, p_expected_version int, p_request_key uuid)`; operational vehicle/capacity/enabled configuration and same-station compatibility synchronization |
| 5 service-only provisioning | `register_rider_membership(p_user_id uuid, p_station_id uuid, p_payload jsonb, p_created_by uuid, p_request_key uuid)`; protected provisioning, not an ordinary operational shortcut |
| 6 assigned rider / own owner read | `get_trip(p_trip_id uuid)`; `list_rider_history(p_cursor jsonb)`; `get_trip_sequence(p_trip_id uuid, p_from_latitude numeric, p_from_longitude numeric)` |
| 6 assigned rider | `resequence_trip(p_trip_id uuid, p_position jsonb, p_expected_version int, p_request_key uuid)` |
| 6 assigned rider | `record_rider_location(p_trip_id uuid, p_latitude numeric, p_longitude numeric, p_accuracy numeric, p_request_key uuid)` |
| 6 assigned rider | `mark_arrived(p_stop_id uuid, p_latitude numeric, p_longitude numeric, p_accuracy numeric, p_request_key uuid, p_fallback_reason text)`; nullable GPS with validated fallback reason |
| 6 assigned rider | `record_delivery(p_payload jsonb, p_request_key uuid)`; payload includes stop/version/result/tendered cash/empties and required failure reason when applicable |
| 6 assigned rider / own owner | `close_trip(p_trip_id uuid, p_expected_version int, p_reason text, p_request_key uuid)` |
| 7 customer | `start_conversation(p_station_id uuid, p_request_key uuid)` |
| 7 allowed participant | `send_message(p_conversation_id uuid, p_body text, p_topic text, p_order_id uuid, p_request_key uuid)` |
| 7 allowed participant | `list_inbox(p_station_id uuid, p_filters jsonb, p_cursor jsonb)`; `get_messages(p_conversation_id uuid, p_after jsonb)`; customer scope derives from actor |
| 7 allowed participant | `mark_conversation_read(p_conversation_id uuid, p_last_seen_message_id uuid, p_request_key uuid)` |
| 7 customer | `request_reservation(p_station_id uuid, p_payload jsonb, p_request_key uuid)`; `cancel_reservation(p_reservation_id uuid, p_expected_version int, p_request_key uuid)` |
| 7 own owner | `respond_reservation(p_reservation_id uuid, p_decision text, p_reason text, p_expected_version int, p_request_key uuid)` |
| 8 own owner | `get_station_report(p_station_id uuid, p_report_kind text, p_start_date date, p_end_date date, p_filters jsonb)` |
| 8 restricted worker only | `run_station_jobs(p_station_id uuid)`; trusted station/job slot and worker identity are derived internally |

Existing signatures/return shapes remain: `my_access()`, `submit_station_registration(text,text,text,text,text,text,text,text,text) → uuid`, `review_station_registration(uuid,text,text) → jsonb`, `provision_station_member(uuid,uuid,text) → void`, `provision_platform_admin(uuid) → void`, `register_driver(uuid,uuid,text,text,text,text,text,int,uuid) → void`, `session_read(text) → text`, `session_write(text,text,int) → void`. Do not add defaulted overloads that confuse PostgREST. Existing anonymous registration plus invitation remains working; this scope does not silently replace it with a new authenticated applicant flow.

### Payloads and returned projections

- Product payload: name, pack text, description, image key, integer price centavos, returnable flag, optional validated load units, active. Station and ownership derive from checked context. No file uploads or arbitrary image URLs.
- Order/quote payload: fulfillment, product UUID/positive quantity lines, address UUID for delivery, ASAP/scheduled selection, requested local time converted with Asia/Manila, and note. Ignore/reject supplied price, totals, fee, distance, role, institution flag and customer ID; database calculates them. Normalize repeated product IDs before pricing/fingerprinting.
- Dispatch proposal: availability/settings/rider/order versions plus selected eligible IDs and optional single overdue-front stop/reason. A preview grants no reservation; dispatch locks and rederives every decision. IDs or supplied order sequence never authorize a different station or arbitrary batch.
- Message cursor: `(created_at,id)` from a visible message; inbox cursor uses last-activity/id. Read receipt is clamped to the last actually visible acknowledged message; no future browser timestamp.
- Delivery payload: stop ID/version, delivered/failed, cash tendered centavos and empties, or required failure reason. Applied amount/change derive from the frozen order/arrival amount. Pickup collection uses the equivalent owner workflow without a rider or trip. Cash verification payload includes the linked collection version and the owner's confirmation or discrepancy reason; both version checks must pass atomically.
- Reservation payload: requested instant, fulfillment, product lines, owned delivery address when needed and note; server derives conversation/customer/station. Acceptance reprices current products and creates one order, snapshot, status log, system card and notifications together.
- List projections contain stable IDs/versions and display fields, not positional synthetic identity. Keep view layout while adding complete live/empty/error data. Order numbers are display identifiers; UUIDs and checked station scope identify records.

## Grants, RLS and races

All new tables have RLS, including protected private tables as defense in depth. Revoke ordinary direct INSERT/UPDATE/DELETE on workflows, schedules, products, addresses, settings and mutation ledgers when their RPC replacements are introduced. Grant only necessary SELECT or narrow read projections. Public station/catalogue/availability fields are explicitly allowlisted. A function grant to `authenticated` alone is not an authorization check.

Public mutating RPC wrappers use `security definer` only to perform the restricted transaction, with a narrow owner, `set search_path = ''`, fully qualified names and explicit actor/role/parent/station checks. Revoke PUBLIC/anon execution and grant authenticated only for listed user operations. Protected helpers remain in `private`; provisioning/session/job operations remain service-only. Job callers derive trusted job identity instead of inventing an Auth user.

Customer reads are own profile/addresses/orders/items/timeline/payment summary/notifications/chat/reservations. Owners read their station business records with current role-specific membership. Riders read own assigned stops/contact snapshots/results, own collection entries and own history. Super Admin is explicitly denied operational rows, aggregates, reports/CSV/print, conversations, notifications, rider positions and mutation-result caches even if given an accidental station membership. Registry responses never embed affected order counts, amounts or identifiers.

Use composite station/customer FKs for all applicable descendants and indexes on authorization/FK filters. Avoid policy recursion through narrow protected lookup helpers. No arbitrary supplied user ID can become a helper authorization result.

For mutations: authenticate and authorize first; acquire request-key lock; return a committed same-key/same-payload result before stale-version checking; different payload with the same key is a conflict. Save the result and every side effect in the same transaction. No provider/network calls while holding database locks.

Canonical lock order: idempotency/actor scope → station eligibility/availability (UUID order) → global rider reservation (user UUID order) → parent records → orders/stops/payments (stable ID order). Preliminary parent lookup is revalidated under locks. Submission/schedules/closures/dispatch share the station lock. A closure that follows a saved order flags it; an order following closure must fail/requote safely. Serialize role/membership revocation with privileged changes as well.

Duplicate detection serializes station/customer/normalized fingerprint. Active duplicate states are `on_hold`, `scheduled`, `confirmed`, `preparing`, `ready`, `out_for_delivery`; the preceding 30-minute interval includes its lower boundary. ASAP uses a stable fingerprint marker. Idempotent retry is checked before duplicate detection, so a double click returns one order rather than creating a hold.

Unique constraints back one active human-rider trip, one active assignment per order, one stop result, one collection per successful result, one accepted reservation order, and one notification per recipient/event. Reservation accept/cancel/expiry and stop arrival/result use row locks and versions. Frozen cash cannot be silently changed by later GPS or product edits. Message quota and default-address switches are serialized, not nonlocking count checks.

Token refresh uses a short database-recorded lease, not a database transaction held across an Auth network call. One request acquires the lease, refreshes and conditionally writes the token pair; a competing request reloads the committed session rather than rotating the same refresh token again. Expired lease holders cannot overwrite a newer version. Read-only polling closes the PHP session after context/refresh handling to avoid holding a session for a long poll. Global logout increments the protected user epoch, deletes the current cookie/session and signs out through Auth; every later PHP request checks its stored epoch. Supabase access-token lifetime remains a provider constraint, so existing JWTs are not described as instantly invalidated at the provider.

## State and algorithm contract

Delivery: `confirmed → preparing → ready → out_for_delivery → delivered → completed`.
Pickup: `confirmed → preparing → ready → collected → completed`.
Future-day orders: `scheduled → confirmed` at eligible service-day opening.
Possible repeat: `on_hold → confirmed/scheduled` after customer confirmation, or `cancelled` on decline.
Customer may cancel `confirmed`, `scheduled` or `on_hold` before preparation. D9 defines the owner's ready-order exception. No ordinary cancellation after delivery or completion. A failed dispatched attempt returns the order to `ready` with redispatch paused until reasoned owner approval. Reattempt resets `queued_at`, not historical creation time.

Priority is exactly `P = U + A + C`: U 40 for ASAP/overdue/due within 60 minutes, 25 after 60 through 180, otherwise 10 later today; A five points per full ten active-queue minutes capped at 25; C ten for a station-classified institution only when the station enables it. Defaults are stored per station. Future-day aging starts at release; paused reattempts do not age. Sort descending P, then ascending queued_at, created_at and UUID. Do not interrupt an order already preparing. Queue position is a changing estimate and capped aging is not a starvation guarantee.

Dispatch chooses a highest-priority feasible ready delivery due within 60 minutes, an enabled free rider with whole-order capacity/fewest trips dispatched at this station today/rider UUID tie-break, then same-barangay orders in queue order within capacity. An infeasible anchor stays visible with a warning while the next feasible order is considered. No overload, partial delivery or implicit second load. SSTF orders the selected stops; priority selects the batch. One reasoned overdue stop may be placed first. Rider stays reserved until all stops resolve and return/staff closure is confirmed.

## Browser and endpoint behavior

Extend the current GET routes rather than replacing the page design. Group new CSRF POST routes under `/customer/account`, `/customer/addresses`, `/customer/orders`, `/station-admin/settings`, `/station-admin/products`, `/station-admin/schedules`, `/station-admin/orders`, `/station-admin/dispatch`, `/station-admin/payments`, `/rider/trips`, and the two role message/reservation prefixes. Preserve existing driver POST paths. `UIController` delegates to complete live presenters; it no longer builds a live page by adding fields to fixture arrays.

JSON read endpoints: `/customer/checkout/quote`, `/customer/tracking/status`, `/station-admin/orders/updates`, role-scoped inbox/thread updates and `/customer/navigation-counts` / `/station-admin/navigation-counts`. Tokens remain server-side. Tracking/queue updates poll every 30 seconds; open conversation every 10 seconds; navigation counts every 60 seconds. Pause/back off when hidden, offline or failing; preserve unsent text and retry keys. Reading via GET does not mutate receipts; visible-message acknowledgement uses a CSRF POST. Server-rendered chat and forms work without JavaScript.

Vendor Leaflet JS/CSS/marker assets under the existing public asset tree and allowlist them. Keep `script-src 'self'`; widen only image sources to the required OSM tile host and data markers if necessary. Display OpenStreetMap attribution. Map/manual coordinate entry works if device location is denied. GPS is shared only during an active trip, at roughly 30 seconds or 50 m, and the rider receives an explanation and can use fallback arrival/result controls.

## Map/reference evidence

Hamtic is PSGC municipality `0600608000`; its reference list contains 47 barangays. The migration will pin official codes/names from the [PSA Hamtic list](https://psa.gov.ph/classification/psgc/barangays/0600608000), not preview labels such as generic Poblacion or Riverside. Source snapshot and retrieval date are stored with the reference seed.

D8's envelope is calculated from Hamtic feature `30758251B48084626937916` in the [geoBoundaries PHL ADM3 metadata](https://www.geoboundaries.org/api/current/gbOpen/PHL/ADM3/), pinned source commit `9469f09`. Metadata identifies NAMRIA/PSA/OCHA sources, represented year 2020 and CC BY 3.0 IGO licensing. The simplified geometry has 47 coordinate vertices and observed extrema latitude 10.584854827000072 / 10.746483607000073, longitude 121.96124135400009 / 122.0737298900001. Proposed six-decimal bounds are rounded outward. This is an application envelope, not a surveyed legal boundary or automatic barangay inference. No pins for existing stations are invented from their address text.

## Verification and release sequence

1. Obtain Phase 1 design/decision approval. This is expressly required by the user-supplied prompt, section 7, Phase 1: "Show the design to the user and get approval before writing migrations."
2. Inspect live schema/constraint names and legacy mappings read-only. Prepare each phase's exact migration, behavior/permission tests, PHP/JS locally, and update the checklist. Do not change a live signature, destructive data mapping or historical value without its specific approval.
3. Present the complete migration file for the user to run in SQL Editor. The prompt, section 6, says: "When a migration is ready, stop and ask the user" to run it. The agent does not apply remote write SQL.
4. After confirmation, verify tables, constraints, RLS, policies, RPCs and grants read-only. Do not deploy code that depends on an unconfirmed migration. Keep staged user changes outside task commits.
5. Each phase runs meaningful FakeGateway controller/service tests, actual-SQL PGlite allow/deny tests, and relevant preview regressions. Update progress with exact output and commit only the phase's files. A commit is not evidence of hosted database application.
6. Exercise two stations/two owners/two customers/assigned riders and Super Admin: cross-scope identifiers, role injection, price/fee tampering, same-key retries, stale versions, closure races, duplicate holds, schedules, pickup invariants, capacity and SSTF, cash/change/empties, fee decisions, reservation double acceptance, rate limiting and cursor reads. Test real concurrency with independent database sessions; sequential PGlite checks are not called concurrent proof.
7. Priority fixture: HMT-104/101/102/103/107 scores 60/55/45/40/30 with institution bonus explicitly on; batches 14 and 5 under 15-capacity riders. Aging at 9/10/49/50/60 minutes yields 0/5/20/25/25. Fee boundaries 0.99/1.0/3.0/3.01 yield 500/1000/1000/1500 centavos after D1 approval. Add a fixed five-stop coordinate fixture including an exact tie and the canonical three-stop B,A,C example.
8. Phase 9 removes every fixture path outside preview, checks 360 px flows and empty/error states, reconciles reports, updates deployment/system-state docs, then deploys only after every migration is confirmed. Run the production smoke suite and real authorized cross-browser order/chat scenarios without inventing acceptance evidence.

Phase 1 baseline checks have passed, as recorded in progress.md. No new workflow, hosted migration, production order, message, location or payment has been created by this design work.
