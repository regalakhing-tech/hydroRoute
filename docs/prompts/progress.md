# HydroRoute customer and station-owner progress

Scope: [requested nine-phase prompt](2026-11-14-build-customer-and-station-owner.md). Updated 14 November 2026, Asia/Manila.

**Current checkpoint: design approved; Phase 2 local implementation is in progress.** The user's "continue all" authorizes the [design and its recommended decisions](2026-11-14-customer-station-owner-design.md). The separate per-migration SQL Editor application/confirmation requirement remains in force.

## Phase status

- [x] Phase 1: inspect all ten customer and seventeen station-owner pages, shared controls, browser scripts, sample loaders, existing backend and all three migrations.
- [x] Phase 1: document every page/form/button/sample dependency below and design the database/RPC/security delta for Phases 2–8.
- [x] Phase 1: run and record the current PHP, database and preview baseline.
- [x] Phase 1: review the proposed design; resolve the pickup-empties reconciliation and newly created operational-rider synchronization gaps. Focused re-review found no remaining Critical or Important issues in those changed sections.
- [x] Phase 1 review gate: user approved design/decisions with "continue all"; approval recorded. Phase documents are being versioned separately from the preserved local baseline.
- [ ] Phase 2: role-specific station authorization and owner profile/pin/service areas/products/rules/schedules; default catalogue/settings initialization.
- [ ] Phase 3: customer profile/preferences/password/logout-all, owned pinned addresses/defaults, live catalogue and delivery quote.
- [ ] Phase 4: atomic order/duplicate/schedule/cancel/track, real dashboard/history/notifications and polling.
- [ ] Phase 5: owner queues/preparation/ready/reviews/pickup/cash/customers/dispatch/trip views.
- [ ] Phase 6: assigned rider trip/location/arrival/result/cash/empties/return/history.
- [ ] Phase 7: saved customer–station inbox/chat/read receipts/rate limits/reservations.
- [ ] Phase 8: scoped reports/CSV/print and restricted scheduled jobs/retention.
- [ ] Phase 9: no live fixture paths, isolation/concurrency/mobile acceptance, documentation, migration verification and production release.

## Current findings

Live signup/login, station registration/approval/invitation, public station reads and owner driver account controls already exist. The working tree also contains user-authored shared session storage and WebP image changes. Preserve those changes; this task has not committed, staged, reverted or deployed them.

`UIController` currently loads fixture arrays even outside preview, replacing only public stations and the owner driver list. Customer actions have no saved-order/address/profile/chat backend. Owner orders/settings/products/schedules/reports/dispatch remain samples or local drafts. Shared messages always seed fixed preview identities. These are inventory findings, not newly implemented features.

The design also addresses roleless membership privilege leakage, Super Admin driver-row visibility, historical address exposure, safe credential management for mixed/shared identities, and direct message writes that could bypass the planned rate limiter. Existing RPC signatures remain compatible.

## Required review and migration checkpoints

The requested prompt's Phase 1 explicitly says: "Show the design to the user and get approval before writing migrations." That approval was received with "continue all". D1–D12 in the design record the approved interpretations, including fee-rounding boundaries, arrival fees, reservations, mixed-product load units and the proposed map envelope. Implementation may proceed; remote SQL application still requires the separate user-run checkpoint below.

After approval, each phase's migration must be presented as a complete file for the user to run in Supabase SQL Editor. The prompt forbids the agent applying remote write SQL. Confirm application, then verify schema/RLS/policies/grants/RPCs read-only. Do not deploy dependent PHP beforehand.

No hosted schema/catalog inspection was performed in Phase 1; the prompt reports the three existing migrations applied, while this task verified their behavior locally. No new hosted order, chat, payment, Auth account or rider location was created. The Word thesis itself was not edited or independently re-audited; its documented conflicts in the canonical plan are recorded for review.

Design verification: all 10 customer and 17 station-owner page headings are present in this checklist; the design contains no TODO/TBD implementation placeholders. Only this progress file and the adjacent proposed design are new tracked-document candidates from this task. No application, migration, asset or pre-existing test file was edited, staged, committed or deployed by this phase's work. Exactly the three pre-existing SQL migration files remain.

## Baseline verification

All commands below exited successfully against the current working tree. Failure messages printed by fake SMTP/network scenarios are expected assertions; the unit tests did not send email or mutate hosted Supabase.

| Command | Observed result |
| --- | --- |
| `php tests/auth.php` | 49 auth assertions passed |
| `php tests/registry.php` | 59 registry assertions passed |
| `php tests/session-store.php` | 7 session-store checks passed |
| `php tests/product-catalog.php` | 16 catalogue assertions passed |
| `node supabase/tests/security.mjs` | 107 PostgreSQL/RLS assertions passed in PGlite |
| `node supabase/tests/registry.mjs` | 36 registry PostgreSQL/RLS assertions passed in PGlite |
| `php tests/ui-smoke.php http://127.0.0.1:8021` | 47 pages and 61 links/assets; privacy/asset boundaries passed |
| `node tests/ui-dom.mjs http://127.0.0.1:8021` | 47 page DOM checks, 281 assertions passed |
| `node tests/fulfillment-dom.mjs http://127.0.0.1:8021` | Checkout modes/validation/restore, pickup tracking/queue and dispatch exclusion passed |
| `node tests/messages-dom.mjs http://127.0.0.1:8021` | 28 messaging DOM checks passed |
| `node tests/messages-regressions.mjs http://127.0.0.1:8021` | 6 persistence/read/focus assertions passed |
| `node tests/product-pricing-smoke.mjs http://127.0.0.1:8021` | Catalogue, pricing, mixed checkout and current WebP routes passed |

Preview checks used a separate temporary PHP server with `APP_PREVIEW=true`; the user's normal server/environment was not changed. PGlite is not hosted Auth/PostgREST/Cron or proof of concurrent database sessions. DOM tests are not visual screenshot verification or evidence that the missing live workflows work.

## Inventory notation

In the inventories below, `[x]` means **inspected and recorded**, not live functionality implemented. Remaining `[ ]` entries describe work to complete in the later phases. Every form/button/link and shared hidden dialog is included so no browser-only control is mistaken for a saved server action.

<!-- Inventory sections follow; keep them in this tracked progress file. -->


## Customer Phase 1 inventory — 14 November 2026

Read-only inventory for `docs/prompts/2026-11-14-build-customer-and-station-owner.md`. This scratch checklist records current behavior and proposed boundaries; it does not approve or implement a database change. Checked all ten customer views, shared messaging, `CustomerData`, `MessagingData`, `Products`, `Fulfillment`, `Pages`, `UIController`, routes, layout, head, feedback, station card, and the three JS files. `/storage/*` is ignored by Git. No production calls or tests were needed for this source inspection.

### Current live behavior and shared data loading

- [x] All customer pages are GET routes generated by `Pages::routes()` and handled by `UIController::page()`.
- [x] Live pages require a session whose role is `customer`. Preview pages permit sample browsing without a login.
- [x] `UIController` always begins with `CustomerData::all()`. Outside preview it replaces only `stations` with `StationDirectory::approved()`; `orders`, `progress`, `addresses`, and `notifications` remain fixtures.
- [x] Customer token is deliberately set to `''` in live mode, bypassing `CurrentUser::token()` and refresh handling. Customer loaders must use the refreshed user token.
- [x] Session name/initials replace `Pages` identity in the layout and overview greeting. Account view independently prints literal Maria Santos/MS.
- [x] Live station list is read from Supabase through `StationDirectory`; the anonymous/public RLS-backed read is intended to expose approved stations. Availability is computed in PHP from weekday/Sunday hours and `closed_notice`, with no schedule windows/dated closures/DB availability RPC yet.
- [x] No customer workflow POST or JSON endpoint exists. Current server POST routes cover auth/signup/setup, station registration, registry decisions/invites, and driver creation/passwords only.
- [x] Every customer save form lacks action/method/CSRF and is intercepted by JS as local preview. Without JS it defaults to a GET of the current URL and does not save anything. Messaging cannot render/open without JS and its textarea has no form name.
- [x] Real signup, email verification, login, logout, and invitation password setup are existing AuthController flows, not functionality provided by these customer page forms. Logout GET checks CSRF in `token`, calls Auth logout and clears current app session. No explicit customer “log out everywhere” action/app-session revocation workflow is exposed.

### Page-by-page control and key checklist

#### `/customer` → `customer/overview.php`

| Done | Element/action | Data consumed | Current behavior / required replacement |
|---|---|---|---|
| [x] | Greeting | `roleInfo.name` | Session name when logged in; fixture otherwise; literal “Good morning” irrespective of time. |
| [x] | Active order panel and progress | `orders[0].status,tone,id,station,quantity,total,stage`; first 5 `progress` tuples `[label,description,time]` | Assumes an order always exists; always delivery-style five-step progress; select own active order and support pickup/hold/scheduled states and no active order. |
| [x] | Track order | `orders[0].id` | Navigates to `/customer/tracking?order=…`; no workflow. |
| [x] | See all stations | none | `/customer/stations`. |
| [x] | Each station card, Order water/View station | `stations[].id,name,area,status,tone,hours,sunday,available`; `Products.round.price` | Links `/customer/station?station=…`; station data live outside preview, price remains PHP constant. “Stations near Guintas” is literal, no distance sorting. |
| [x] | Recent order table and each View | `orders[1..].date,station,quantity,total,status,tone,id` | Links tracking. No real recent orders, empty state selection, unread messages, or unread notification summary. |

#### `/customer/stations` → `customer/stations.php`

| Done | Element/action | Data consumed | Current behavior / required replacement |
|---|---|---|---|
| [x] | Search stations (`station_search`) | rendered text of `#station-list` | JS substring filter only; no form/server filter. |
| [x] | Availability select (`availability`) | `all/open/closed` | JS filter; each row is assigned `open` only when its array index is zero, ignoring actual `available`. This is wrong for real alphabetical rows and multiple open stations. |
| [x] | Station name/location/status row | `stations[].id,name,area,status,tone` | Live station public data outside preview; no served barangays/distance/fee. |
| [x] | Price teasers | `Products.small.price`, `Products.bottle350.price` | Same global PHP 10/220 prices for every station; replace with station catalogue minima, respecting availability. |
| [x] | Each View station | `stations[].id`; row index for visual button variant | Navigates `/customer/station?station=…`; link works. |
| [x] | No-results message | visible row count | Browser filter empty state exists; no friendly directory failure flash and no dedicated initial empty state. |

#### `/customer/station?station=…` → `customer/station.php`

| Done | Element/action | Data consumed | Current behavior / required replacement |
|---|---|---|---|
| [x] | Station resolution | `stations[].id`; GET `station` | Defaults to literal `guintas`; rejects array values by choosing empty ID. Real directory slugs might not contain guintas; explicit selection links work. Add station-scoped live loader with an honest not-found state. |
| [x] | Find stations / not-found Find stations | none | `/customer/stations`. |
| [x] | Public station details and weekly hours | `name,area,status,tone,hours,sunday` | Address is fabricated as `National Road, Brgy. {area}` even though directory supplies an actual address. Mon–Sat all repeat one range; no full weekly schedule, contacts, description, map pin, service areas or quote. |
| [x] | Message station | `station.id` | `/customer/messages?station=…`; only selects a seeded local conversation. New real station slug fails that selection. |
| [x] | Closed block and Find an available station | `available,next` | Returns before catalogue; cannot submit an ordinary order at a closed station. Must align future booking/reservation availability with system-plan rules. |
| [x] | Product choice form | `Products::quote([]).items` | GET `/customer/checkout`; hidden station. All seven global products shown. |
| [x] | Per-product minus / number / plus | `items[id].name,description,price,quantity,per`; `Products` image | Numeric fields named `round/small/faucet/jug/bottle350/bottle500/bottle1l`, 0–20 each; JS counters clamp and recalculate DOM total. No total-order 20-container enforcement or availability validation. |
| [x] | Continue to order | quantity fields and station | Browser navigation with basket in query; JS blocks zero subtotal, PHP checkout also shows empty basket. Nothing persisted to station. |
| [x] | Home address link | none | `/customer/addresses`; literal “Home · Guintas, Hamtic”. No actual default address. |
| [x] | Order summary and fee prose | quote total | Literal cash on delivery, PHP 5–15 fee, and returnable explanation. No fulfillment selection until checkout. |

#### `/customer/checkout` → `customer/checkout.php`

| Done | Element/action | Data consumed | Current behavior / required replacement |
|---|---|---|---|
| [x] | Station/closed/empty guards and recovery links | GET station (default guintas); `stations`; `Products::quote($_GET)` | Find stations, Back to station, Choose products are real navigation only. |
| [x] | Checkout form | `station.id,name,area,status,tone,hours,sunday`; quoted item fields; GET fulfillment | `data-local-form`, no POST target. Persistence key `customer-checkout-v2-{station}-{seven canonical quantities}[-pickup]`. |
| [x] | Delivery / Pickup radio cards | `fulfillment=delivery/pickup` | JS shows/disables delivery address or shows pickup location, changes timing/notes/payment labels, zeroes pickup fee; no saved order. |
| [x] | Saved address select | `addresses[].label,km` builds fee map; **options hardcoded separately** | Only `home` / `work`; labels use sample address text; default always home. PHP uses `$fees['home']`, requiring a Home label regardless of actual default. No UUID, owned pin, distance or eligibility checks. |
| [x] | Manage addresses | none | `/customer/addresses`. |
| [x] | ASAP / scheduled radio (`delivery`) | literals `asap/scheduled` | JS enables/requires date/time only for scheduled. Without JS they remain editable. |
| [x] | Preferred date and time | literal date/min `2026-11-02`; times `10:00,13:00,15:00` | No today/7-day validation or station service-window check. |
| [x] | Return fewer / numeric returns / Return more | quote returns | 0–40; independent of actual products/returns policy; local counters only. |
| [x] | Notes | `notes`, max 200 | Browser draft only. |
| [x] | Cash payment radio | `payment=cash` | Delivery/pickup wording changes; actual payment validation absent. |
| [x] | Item summary, hidden item quantities and quantity | `quote.items[id].quantity,name,per,unit,price,subtotal`; `quote.quantity,total` | Browser-controlled values and static prices; DB must calculate authoritative snapshots/totals. |
| [x] | Fee and total | `Products::deliveryFee(address.km)`; quote subtotal | JS selects static fee map and adds subtotal. Fee bands literal in prose. No quote endpoint, real distance, fee-rule version, out-of-range or pin validation. |
| [x] | Save order draft | all enabled non-sensitive controls | Saves localStorage; clearly says no order sent. No `submit_order`, request key, field errors, duplicate decision, scheduled persistence or tracking redirect. |
| [x] | Back to station | station.id | `/customer/station?station=…`. |

#### `/customer/orders` → `customer/orders.php`

| Done | Element/action | Data consumed | Current behavior / required replacement |
|---|---|---|---|
| [x] | Order water | none | `/customer/stations`. |
| [x] | Search orders `order_search` | rendered table text | Client substring filter by order/station/other rendered content. |
| [x] | Status select `status` | literals All/Out for delivery/Delivered/Picked up | Client exact status filter; missing active/completed/cancelled groups and all real workflow statuses. |
| [x] | Rows and View links | `orders[].id,date,station,quantity,total,fulfillment,status,tone` | Links tracking, formats every quantity as “containers” even for mixed products. Real own-order list/pagination absent. |
| [x] | Empty/filter/footer | visible row count | Search-empty state; literal “Showing 3 sample orders · November 2026”; initial empty list needs real wording. |

#### `/customer/tracking?order=…` → `customer/tracking.php`

| Done | Element/action | Data consumed | Current behavior / required replacement |
|---|---|---|---|
| [x] | Order lookup/not-found My orders | `orders[].id`, GET order defaults `HMT-104` | Finds fixture only; not-found says “sample history”. No token-authorized lookup. |
| [x] | Station lookup and header | `order.station_id`; `stations[].id,name,area,status,hours,sunday` | After live station replacement a fixture station may not exist, causing null access; use joined order snapshot/public station data. |
| [x] | Message station / My orders | station.id | Links messages by station and orders list. Does not pass optional order context into chat. |
| [x] | Progress and history | `order.stage,status,date,fulfillment`; `progress` or `Fulfillment::pickupProgress()` | Fixture timeline, no logs or polling. Delivered step is rewritten with literal 10:15 AM when stage >=4. Pickup progress uses “Picked up” display, while workflow plan uses `collected` then `completed`. |
| [x] | Order items, fee, total/payment | `order.quantity,total,fulfillment`; `Products.round.name,price` | Treats every order as one round refill line; derives fee by subtracting constant product price. No saved item/fee snapshots. |
| [x] | Delivery rider and Contact rider | literals Juan Reyes/JR | Contact is a toast explaining fictional rider, no phone service. No assigned driver lookup. |
| [x] | Delivery address and View address | literal Home/Purok 2, Guintas, Hamtic, Antique | Link `/customer/addresses`; missing immutable order address snapshot/pin. |
| [x] | Pickup information | station details | Shows hours and instruction to bring empties/pay; correctly omits rider/address branch. Needs actual pickup instructions/ready state. |
| [x] | Need help → View station | station.id | `/customer/station?station=…`. |
| [x] | Cancellation block | order.status | Always “Cancellation unavailable”; no reason form, allowed-status condition or cancel RPC. No on-hold original-order link / confirm duplicate action. |

#### `/customer/addresses` → `customer/addresses.php`

| Done | Element/action | Data consumed | Current behavior / required replacement |
|---|---|---|---|
| [x] | Add address | none | Anchor `#address-form`; no modal. |
| [x] | Saved address cards | `addresses[].label,address,note,default` | Fixture cards; default styling and label-specific Home icon. No address IDs supplied. |
| [x] | Each Edit | none | Same `#address-form` anchor; does **not** populate selected address. |
| [x] | Each non-default Make default | none | Toast only; does not even update card/default state. |
| [x] | Add or update form | `label` required max40; `barangay` required; `street` required max150; `landmark` max200; `default` checkbox | `data-local-form`, persistence `customer-address`; same single draft for every card. Barangays only Guintas/Poblacion/Riverside, no reference list. |
| [x] | Save address | form controls | LocalStorage only, fixture list never updated. No add/edit/delete/default RPC, hidden address ID, delete control, pin/map/device location/pin_source, ownership checks or soft-delete behavior. |

#### `/customer/notifications` → `customer/notifications.php`

| Done | Element/action | Data consumed | Current behavior / required replacement |
|---|---|---|---|
| [x] | Notification preferences | none | `/customer/account`. |
| [x] | Notification rows and each View order | `notifications[].icon,title,time,body,order_id` | Tracking link only; first row unread dot based on index, no notification ID/read state. |
| [x] | Count/footer | literals | “3 updates”; “all caught up” and “Sample updates from November 2026”. No real list/unread count, mark one/all read, or message/reservation links. |

#### `/customer/account` → `customer/account.php`

| Done | Element/action | Data consumed | Current behavior / required replacement |
|---|---|---|---|
| [x] | Identity banner and stats | counts of `orders`,`addresses`; literals MS/Maria Santos/member since2026 | Ignore session identity for banner; counts are fixture lengths. |
| [x] | Personal information form / Save changes | literal first_name Maria, last_name Santos, email maria.santos@example.com, phone09171234567 | LocalStorage key `customer-account`; name/email/phone required, names max60, phone permissive 10–20 chars. No server validation, profile/Auth sync, Philippine normalization, or authenticated contact save. Prompt asks display name/mobile; editable email needs deliberate Auth verification policy or removal from editable fields. |
| [x] | Notification form / Save preferences | `order_updates=on` initially checked; `station_updates=on` unchecked | LocalStorage `customer-preferences`; no saved preference schema or actual effect. Keep as scoped persisted preference requirement if retained in live UI. |
| [x] | Default delivery address / Manage addresses | `addresses[0].label,address,note` | Always selects first address and labels Default even if different flag. Assumes nonempty list. `/customer/addresses`. |
| [x] | Account shortcuts | none | `/customer/orders`, `/customer/notifications`, `/customer/messages`. |
| [x] | Password options | none | Toast only; literal says password services unconnected despite working auth elsewhere. Need current-user password change via Supabase Auth. |
| [x] | Log out | layout logoutHref | Actual `/logout?token=CSRF` with real session; `/login/customer` for anonymous preview. SweetAlert confirmation from app.js. No explicit all-devices button or verified global app-session invalidation. |

#### `/customer/messages` → `customer/messages.php` and `shared/messages.php`

| Done | Element/action | Data consumed | Current behavior / required replacement |
|---|---|---|---|
| [x] | Seed and role boundary | `MessagingData::forRole('customer')`, not `$sample` | Independently injects fixtures even outside preview; `version,role,viewer,threads` must become live role-authorized projection. Other roles rejected by fixture helper, but real DB isolation still required. |
| [x] | Search conversations | `threads[].name,order` | Client-only substring search; no topic or unread filter. |
| [x] | Dynamic thread buttons | `id,name,initials,messages[-1].body`, last sender vs viewer, read marker | Select thread, update URL `?thread=…`, focus composer, mark local read when visible. No start-conversation form/action. |
| [x] | Query selection and invalid banner | GET `thread` or `station` | Only seeded threads selectable. Invalid/array query shows unavailable banner then selects first thread. Requires nonempty seed; empty live inbox would currently crash. |
| [x] | Back to conversations | none | Mobile panel toggle/focus only. |
| [x] | Conversation header/context | thread.name,initials,subtitle,order,method | Order link `/customer/tracking?order=…`; no-order context “Ask about refills, opening hours or your next visit”. Always Local preview. No closed notice/next opening. |
| [x] | Message rendering and read presentation | messages[].id,sender,body,at; viewer; peer local read | textContent plain-text output; client-local dates/times; labels Saved on this device / Seen in preview / This session only. No server timestamp/cursor/read receipt. |
| [x] | Composer / Send | textarea `chat-body`, maxlength2000, no name | Trim body; Enter submits, Shift+Enter newline; blank/overlong rejection; only local save; no rate limit, topic, optional order selector, CSRF, server request key or sender authorization. |
| [x] | Emoji toggle and five emoji buttons | literal 😊 👍 💧 🙏 ✅ | Expands hidden `#chat-emojis`, inserts at cursor within 2000-char bound, closes and focuses. Preserve as enhancement. |
| [x] | Message count, feedback and noscript | current textarea length | Browser only; no fallback server form. Explicit local-preview disclosures appear in list/composer/log. |
| [x] | Reservations | none | No request button, dialog, date/time/fulfillment/items/address/note form, card, cancel requested action, or decision/status rendering. All are missing UI controls as well as backend. |

### Literal fixture values and dependencies to eliminate outside preview

`CustomerData` owns these complete data groups (the view key lists above specify consumption):

| Group | Current literals |
|---|---|
| stations | `guintas`: Guintas Water Station, Guintas, open until6PM, available true, Mon–Sat7AM–6PM, Sunday8AM–4PM. `poblacion`: Poblacion Water Station/Poblacion, opens tomorrow7AM, unavailable,7AM–5PM, Sunday Closed, next Nov3 2026 7AM. `riverside`: Riverside Water Station/Riverside, Under maintenance, unavailable,8AM–5PM, Sunday Closed, reopening unknown. |
| orders | `HMT-104`: delivery, guintas, stage3, Nov2 2026, Guintas Water Station,3,PHP70,Out for delivery/blue. `HMT-098`: pickup,poblacion,stage3,Nov1 2026,Poblacion Water Station,2,PHP40,Picked up/green. `HMT-091`: delivery,guintas,stage4,Nov1 2026,Guintas Water Station,3,PHP70,Delivered/green. |
| delivery progress | Confirmed9AM (“Your order has been received”); Preparing9:10AM; Ready9:25AM; Out for delivery9:40AM; Delivered waiting/—; Completed/—. |
| pickup progress (`Fulfillment`) | Confirmed9AM; Preparing9:10AM; Ready for pickup9:25AM; Picked up10:15AM. |
| addresses | Home: Purok2,Guintas,Hamtic,Antique; Near barangay hall; default true;1.5km. Work: National Road,Poblacion,Hamtic,Antique; Reception,ground floor; default false;4.2km. |
| notifications | “Your water is on the way”, rider Juan Reyes/orderHMT-104/Home, Today9:40AM,truck; “Your order is confirmed”, Guintas/3 refills,Today9AM,check,HMT-104; “Pickup completed”,HMT-098,Nov1 2026 10:15AM,bottle. |
| identity (`Pages`) | Customer/Maria Santos/MS; “Your daily water, delivered”; reused layout label/caption/banner/description/icon. Session replaces roleInfo name/initials, not account literals. |
| customer chat | Viewer `customer:maria`; threads `guintas-maria`,`poblacion-maria`,`riverside-maria`, station names above, initials GW/PW/RW, subtitle Water station. Guintas linksHMT-104/delivery; other two have null order/method. Every thread initial messages: customer asks about water order at Nov2 2026 9:30AM, station replies “Of course. Send your order number and we can help with the details.” at9:32AM. |

`Products::all()` is a global constant catalogue in pesos; these values are valid proposed seed defaults, not authoritative live per-station data:

| ID | Name / pack | Price | Returnable | image |
|---|---|---:|---|---|
| round | Water container refill (18.9 L),1 piece | 20 | yes | water-jug.webp |
| small | Small water container refill (9.5 L),1 piece | 10 | yes | water-jug.webp |
| faucet | Water jug with faucet refill,1 piece | 20 | yes | faucet-container.webp |
| jug | New water jug with faucet,1 piece | 190 | no | faucet-container.webp |
| bottle350 | Bottled water350ml,1 box for40pcs | 220 | no | water-bottle.webp |
| bottle500 | Bottled water500ml,1 box for35pcs | 270 | no | water-bottle.webp |
| bottle1l | Bottled water1L,1 box for20pcs | 260 | no | water-bottle.webp |

Each also supplies `description,unit,per`. Refill descriptions describe the named container; jug says filled with water; bottle descriptions repeat box counts. `Products::quote` defaults round quantity3 if none of the seven product keys is present, otherwise unspecified quantities0; accepts integer strings/ints only, clamps each0–20, computes subtotal/quantity/returns in PHP. It does not enforce the order-wide limit. `deliveryFee` returns PHP5 below1km,PHP10 through3km,PHP15 above. `UI::productImage` also reads `Products` for image and alt text, so swapping visible price fields alone does not fully remove the constant catalogue dependency.

Other literal/sample data inside views: fabricated National Road station address; Home address options/sidebar/location; Maria contact fields; fixed member year; checkout Nov2 date and10AM/1PM/3PM times; rider Juan/JR; fixed three-order/three-update footers; Guintas nearby heading; sample cancellation/progress/history messages; hardcoded fee prose. Keep generic cash/pickup instructions but derive their contextual values from saved records/settings.

### Shared layout, navigation, hidden controls and browser persistence

| Done | Shared surface | Exact behavior / gap |
|---|---|---|
| [x] | Desktop customer nav | Overview `/customer`, Find stations `/customer/stations`, My orders `/customer/orders`, Messages `/customer/messages`, Addresses `/customer/addresses`, Notifications `/customer/notifications`, Account `/customer/account`. Stations active for station/checkout; Orders active for tracking. No unread badges. |
| [x] | Mobile header/tab bar | Logo `/`; account avatar `/customer/account`; tabs first4 nav entries (Overview/Stations/Orders/Messages) plus Notifications. Addresses reachable through Account. No customer More button. |
| [x] | Global links | Skip to `#main-content`; desktop logo `/`; sidebar Logout as above. Preview bar “View login” `/login/customer` only in preview. |
| [x] | Hidden `role-switch` dialog | Sidebar Switch role opens in **both** live and preview; close button and links `/customer`,`/rider`,`/station-admin`,`/super-admin`. Text says sample screens. Live role guards still apply; do not represent this as a station/role authorization switch. Gate preview explorer or replace with authorized switching design. |
| [x] | Hidden `confirm-action` dialog | Close, Cancel, Confirm; generic “Update this preview?” and sample-only message. Customer views currently have no `data-status` controls, so it is present but unused except shared JS infrastructure. |
| [x] | SweetAlert logout | Cancel/Stay logged in or Yes, log out; appends `logged_out=1`; follows real logout link when confirmed. Native follow works if SweetAlert absent. |
| [x] | Toast/server flash | feedback partial consumes Session flash into hidden data attribute; app.js shows SweetAlert/fallback toast. Existing success feedback must distinguish live DB writes from previews. |
| [x] | Footer | Outside preview literally says orders/trips show sample data until ordering connected; messages screen omits ordinary footer. |
| [x] | All loaded assets | app.js, messages.js, fulfillment.js load deferred on every page; mode/select/schedule logic is browser enhancement only. |

Browser storage/current cross-tab behavior:

- [x] `hamtic-ui:form:customer-account`, `…:customer-preferences`, `…:customer-address` hold form drafts (not scoped to actual authenticated user). Hidden fields serialize on save but are skipped on restore; disabled and sensitive names (`password|secret|token`) are excluded; no password stored.
- [x] `hamtic-ui:form:customer-checkout-v2-{station}-{quantities}[-pickup]` holds checkout drafts separately by station/basket/mode. Fulfillment module restores mode-specific drafts, toggles required/disabled address/date/time, and recalculates fee/total. Drafts do not update visible saved addresses or order lists.
- [x] `hamtic-ui:status:{pathname+sorted-query}:{element-id}` is generic local status storage. No customer page currently exposes a status mutation control.
- [x] `hamtic-chat:v1:thread:{thread-id}` stores up to300 valid local extra messages; same key shared between customer/station previews. Read keys `hamtic-chat:v1:read:{viewer}:{thread-id}` store last message ID. This is device-local fixture identity, not authentication or multi-instance persistence.
- [x] Chat re-reads before appending, reconciles in-memory unsaved messages, handles corrupt/unavailable localStorage, listens to browser storage events, marks read only when active/visible, and shows session-only feedback when save fails. No network polling or request to another browser/device occurs.
- [x] General search/filter/counters/order totals/dialogs/emoji/mobile panel actions are real DOM interactions only. Generic CSV export/status handling exists in app.js but no customer view has export controls.

### Proposed service/controller/route boundaries for the approved design

Names below are PHP boundaries and candidate routes, not implemented signatures or authorization approval. Match exact RPC signatures to the full database design/system-plan section11; keep workflow writes in single checked RPCs using current user token.

| Boundary | Read/render responsibilities | POST / JSON responsibilities |
|---|---|---|
| Customer profile service + customer controller | Own profiles/contact/creation date, preference fields, own address/order counts; refresh layout session identity after save | POST `/customer/account` → narrow profile save RPC; POST `/customer/account/preferences` if toggles retained; POST `/customer/account/password` → Supabase Auth current-user password API; POST `/customer/account/logout-all` → explicit Auth + app-session revocation design. Every POST CSRF checked. |
| Customer addresses service | Owned non-deleted addresses, actual default ID, barangay reference options, pin eligibility | POST `/customer/addresses/save`, `/delete`, `/default` → owned address RPCs; include stable address ID, validated fields, pin_source and coordinates; keep order snapshots valid after address deletion. Render forms usable without JS, enhance with vendored Leaflet/device pin. |
| Public station/catalogue service (extend StationDirectory) | Approved station resolved by stable UUID/slug, active station products, weekly windows/closures, service areas, public profile; use `get_station_availability` everywhere | Customer quote JSON endpoint e.g. GET `/customer/checkout/quote` → `quote_delivery` with validated item/address references and current token. Resolve UUID server-side; browser never supplies authoritative price/fee/distance. |
| Customer orders service + controller | Overview projections, filtered own list, own order detail/items/snapshots/status logs/driver contact and pickup instructions | POST `/customer/orders` → `submit_order(station_id,payload,request_key)`; POST `/customer/orders/cancel` → `cancel_order` with reason/version; POST `/customer/orders/duplicate` → `confirm_duplicate`; GET `/customer/tracking/status?order=…` → owned compact JSON every30s. POST returns303 and preserved field errors on failure. |
| Notifications service/controller | Own notifications, real unread count, linked order/conversation/reservation target; nav badge shared projection | POST `/customer/notifications/read`, `/read-all` through narrow checked RPCs; compact JSON badge endpoint every60s, potentially shared with messaging counts. |
| Shared messaging service with customer/station controllers | `list_inbox` and `get_messages(after)`, authorized participants; closed notice/next opening; empty inbox without crashes | POST `/customer/messages/start` → `start_conversation`; POST `/customer/messages/send` → `send_message`; POST `/customer/messages/read` → `mark_conversation_read`; GET `/customer/messages/inbox`, `/messages/thread` for JSON10s conversation polling/60s badges. Parallel station endpoints enforce membership, never role text from browser. Server-render initial list/log and working fallback forms. |
| Shared reservations service | Reservation cards/status/linked scheduled order; reference real catalogue/address/schedule data | Customer POST `/customer/reservations` request; POST `/customer/reservations/cancel` requested only; station respond endpoint calls `respond_reservation`, atomically validates/creates scheduled order/system message. No direct browser writes. |

Cross-cutting design requirements:

- [ ] Outside preview do not merge a real partial projection with fixture fallbacks (`+ $sample`); each live screen gets a complete real/empty/error projection. Preserve existing fixture shapes only in explicit preview path.
- [ ] Surface failure as friendly refresh flash/empty-error state and log exception class only; `StationDirectory` currently logs exception message and quietly returns empty list, which does not distinguish failure from no approved stations.
- [ ] Add IDs/version/request keys/read-state/snapshots where the current sample shape lacks them. Formatting should not lose UUIDs/centavos; DB stays integer centavos and presenter formats PHP currency.
- [ ] Make overview/account/checkout/tracking safe with no orders, no addresses, no default, missing station, empty inbox, mixed products and deactivated historical products.
- [ ] Include stationary notifications/preferences and live navigation badges in the schema/API plan; UI controls reveal these requirements even if not all are named in the initial table list.
- [ ] Keep customer ownership, station membership, rider restrictions and Super Admin transaction/chat denial enforced in DB and controller. Preview query/localStorage identity must not authorize anything live.
- [ ] Pinless addresses are pickup-only; pickup orders never require delivery address, driver, trip or fee. Shared UI state does not substitute for server/RPC invariants.
- [ ] Database quote/result wins over all JS/PHP previews; catalogue IDs/price snapshots must be station-specific; duplicate handling/cancellation and chronological logs must follow the system plan.
- [ ] Generic role explorer, sample labels, preview disclosure, browser-only success text, fixture rider/account/address and sample footers need preview gating/live replacement together with data loaders.
- [ ] Phase1 leaves all current controls unchecked for live acceptance until an approved migration is manually applied and verified; this inventory contains no implementation or live-data proof.


## Phase 1 station-owner UI inventory

Inventory date: 2026-11-14. Source inspected: the complete build prompt, all 17 `app/Views/station-admin/*.php` views, shared messages, `StationData`, `Products`, `MessagingData`, `Fulfillment`, `RiderData`, `Pages`, `UIController`, `StationController`, shared `Controller`, `Registry`, `CurrentUser`, `routes/web.php`, app layout/head/feedback, all three browser scripts, rider views and UI/messaging contracts. This document records existing behavior; it does not implement or approve a database design. No live data was accessed and no functional tests were run.

### Existing data boundary

- [x] `Pages::routes()` defines 17 station GET screens. Every route calls `UIController::page()`; only the two driver actions have station POST routes.
- [x] `UIController::page()` initializes `StationData::all()` in both preview and live modes. Live mode replaces only `sample.drivers` with `Registry::drivers($token, $user['station']['id'])`; all other station keys remain samples. Thus live authentication alone does not make operational pages real.
- [x] Live layout identity uses session name/initials and session station name as `roleInfo.caption`; otherwise defaults are Ana Dela Cruz/AC and Guintas Water Station. `sample.station` exists but is not directly read by the current station views. The station ID used by driver code comes from the session, not a form field.
- [x] `Registry::drivers` selects real driver identity/contact/vehicle/capacity/active fields using the user's access token and station filter. Its presentation maps active to `Available`, inactive to `Inactive`, and every real driver to `No active trip`. Availability and current trip are not operational live state yet.
- [x] The only station POST endpoints are `/station-admin/drivers` and `/station-admin/drivers/password`. There are no order, dispatch, pickup, profile, product, hours, service-area, rule, cash, report, message or reservation endpoints.
- [x] All `data-local-form` forms have no server action/method or CSRF field. JS prevents submission and saves drafts; without JS they default to GET on the current URL and perform no write. Search, filters, status confirmation, dialogs, exports and chat require JS. Real driver POST forms work without JS.

### Shared sample schema and fixed catalogue

| Source/key | Current shape / literal fixtures | Screens consuming it |
| --- | --- | --- |
| `StationData.station` | `Guintas Water Station` | No direct current station-view read; layout caption is separately `Pages`/session supplied. |
| `StationData.orders` | Tuple `[order, customer, barangay, quantity, priority, status, requested_time, fulfillment]`. HMT-104/Maria Santos/Guintas/3/60/Preparing/10:20 AM/delivery; HMT-105/Luis Reyes/Guintas/2/55/Confirmed/10:35 AM/pickup; HMT-106/Ana Cruz/Poblacion/4/45/Ready/10:12 AM/delivery; HMT-107/Lito Garcia/Guintas/3/40/Ready/10:40 AM/delivery; HMT-109/Emilia Ramos/Guintas/2/35/Ready/11:05 AM/pickup. | Overview, orders, deliveries, pickups. |
| `StationData.trips` | `08`: Juan Reyes/JR/Guintas/In progress/capacity15; HMT-098 Nora Flores 4 Delivered, HMT-099 Jose Ramos 4 Next stop, HMT-100 Rosa Perez 4 Pending. `07`: Carlo Diaz/CD/Poblacion/Returning/capacity15; HMT-096 Leo Garcia 4 Delivered, HMT-097 Mila Perez 4 Delivered. `06`: Ben Cruz/BC/Guintas/Completed/capacity15; HMT-093 Linda Ramos 4, HMT-094 Rene Cruz 3, HMT-095 Rita Santos 3, all Delivered. | Deliveries, trip. |
| `StationData.drivers` | Tuple `[name, initials, phone, status, trip, capacity]`: Juan Reyes In progress Trip08/15; Carlo Diaz Returning Trip07/15; Ben Cruz Available/no active trip/15; Mark Flores and Rico Perez Available/no active trip/12. Sample phones 0917 555 0101–0105. Live rows append `[user_id,email]` at indexes6/7. | Drivers, delivery summary; dispatch rider selector is separately hardcoded. |
| `StationData.customers` | `[name, barangay, type, phone, count]`: Maria Santos/Guintas/Household/12; Luis Reyes/Guintas/Household/8; Ana Cruz/Poblacion/Household/6; Guintas Learning Center/Guintas/Institution/18. Sample phones 0917 555 0201–0204. | Customers. |
| `StationData.areas` | Guintas, Poblacion, Bia-an, Buhang. | Service-area settings. |
| `StationData.days` | Monday through Sunday. | Schedules. |
| `StationData.sales` | Nov1 and Nov2 2026, PHP3,450 each. | No current direct consumer; sales report assigns `$s` but renders literals. |
| `Products::all()` | `round`: 18.9L refill PHP20; `small`:9.5L refill PHP10; `faucet`:faucet-jug refill PHP20; `jug`:new filled jug PHP190; `bottle350`:40×350ml PHP220; `bottle500`:35×500ml PHP270; `bottle1l`:20×1L PHP260. First3 returnable. Each has pack/name/price/image/description/unit/per/returnable. Images use existing water-jug/faucet-container/water-bottle webp assets. | Products; orders/trip use `round` price. Catalogue is global PHP data, never station product rows. |
| `Products::deliveryFee(km)` | PHP5 under1km; PHP10 at1–3km inclusive; PHP15 beyond3km. | Order inspector and trip force1.5km/PHP10; product policy repeats these literal bands. No live address-distance calculation. |
| `Products::quote(query)` | Global catalogue quote, clamps each product quantity0–20, defaults3 round refills if no product query keys; returns items/total/quantity/returns. | Customer basket dependency; no station DB quote. |

### Every station page, form and action

Each checkbox means inventoried, not implemented.

#### Overview — `/station-admin` (`overview.php`)

- [x] Reads `sample.orders`: queue count; first3 order rows; final3 rows in ready-for-dispatch table. That final slice includes HMT-109 pickup, so this overview subsection is not filtered consistently with the delivery page.
- [x] Literals: Monday November2 2026; Open until6PM;24 orders today; PHP3,450 collected;3 available drivers; HMT-104/Maria Santos priority explanation U40+A10+C10=60.
- [x] Links/buttons: `View full queue`→orders; each `View`→orders?order=fixture; `Manage priority rules`→settings/order-rules; `Manage schedule`→schedules; `Build a trip`→deliveries#dispatch. No form or mutation.
- [ ] Required live replacement: today/status/cash/overdue/availability/driver metrics, real priority explanation and correctly delivery-only dispatch list.

#### Order queue — `/station-admin/orders` (`orders.php`)

- [x] Reads all `sample.orders`, default selects first row; string `?order=` selects a fixture or silently falls back. No empty-list handling. Header message recipient is hardcoded order→customer slug map (maria/luis/ana/lito/emilia).
- [x] Browser filters (not submitted forms): search `order-search`, status `status` all/Preparing/Confirmed/Ready, fulfillment `fulfillment` all/delivery/pickup. Table stats count the PHP fixtures; statuses are formatted by `Fulfillment::status` (pickup Ready→Ready for pickup).
- [x] Links/buttons: `Message customer`→messages?customer=slug; every `View`→same page?order=id; `Start preparing` sets `selected-order-status` to Preparing; `Mark ready` sets it to Ready or Ready for pickup; selected pickup has `Open pickup queue`→pickups?order=id; selected delivery has `Open dispatch`→deliveries#dispatch.
- [x] `Start preparing`/`Mark ready` open shared `confirm-action` dialog (Cancel/Confirm/close); confirmation changes only the inspector DOM and localStorage under the full path/query. Table rows, stats, other pages and customer do not change. Actions are not gated by actual current status.
- [x] Inspector literals/computations: all items described as quantity×5 gallon round refills; total=`quantity×Products.round.price + (pickup?0:deliveryFee(1.5))`; payment COD/on pickup; fixed empty-container policy; scalar priority only.
- [ ] Missing controls: cancellation/reason, duplicate confirmation/decline, U/A/C details per row, overdue reason/dismiss, optimistic version/idempotency, live polling, cash completion and real order items/snapshots.

#### Deliveries — `/station-admin/deliveries` (`deliveries.php`)

- [x] Reads `sample.orders` delivery+Ready only, `sample.trips`, `sample.drivers`. Tabs accept only active/ready/completed (`?tab=`); `View trip`→trip?trip=id. `Build a trip` in header links to same-page dispatch anchor. Stats derive sample active/completed trips and driver status.
- [x] Dispatch form draft key `station-dispatch`: `rider` hardcoded ben/Ben Cruz available15 or mark/Mark Flores available12 (even in live mode); `area` only Guintas; selected ready orders hardcoded by Guintas filter; total load shown against literal15; `note` defaults “Handle containers carefully.” max200. `Save dispatch draft` submit; `View active trip`→trip default08.
- [x] Form saves browser draft only; it neither creates trip/stops nor notifies a rider. No selection checkbox/removal, free-rider RPC, capacity enforcement, distance, station/address coordinates, SSTF route, overdue move/reason, atomic dispatch or driver occupancy update.
- [ ] Required live replacement: preview/confirm trip with editable batch, real availability/capacity and persisted rider assignment; ready-order warning when over every vehicle capacity.

#### Pickup queue — `/station-admin/pickups` (`pickups.php`)

- [x] Reads `sample.orders` filtered pickup, ignores `?order=`. Stats count initial fixtures. Explicit browser-preview notice.
- [x] Browser filters: `pickup-search`; `pickup-status` all/Confirmed/Ready for pickup/Picked up. Each row has `Mark ready`, `Confirm collection`, and `View`→orders?order=id. First2 actions use shared confirmation dialog and per-page `pickup-status-ID` localStorage badges.
- [x] `fulfillment.js` observes badge text, updates row filter status, disables Mark ready when Ready for pickup/Picked up, and enables collection only when Ready for pickup. PHP summary counts still do not update and other pages do not change. “Picked up” is a preview label, not the intended canonical `collected` workflow.
- [ ] Missing real collection and completion/cash verification; no payment/empties inputs or backend action.

#### Drivers — `/station-admin/drivers` (`drivers.php`)

- [x] Reads `sample.drivers`; real live list as described above. Header and empty-state `Add driver`→drivers/add. Browser search `driver-search`; availability filter all/Available/In progress/Returning/Inactive. Counts derived from presented data.
- [x] Available row `Assign trip`→deliveries#dispatch (does not preselect driver). Busy sample row `View trip`→trip08 if initialsJR, otherwise07; inactive row has neither. This busy routing remains a fixture heuristic.
- [x] Only real rows include `Set password`, opening native dialog `driver-password-{user_id}`. Dialog close icon and Cancel dismiss. Form POST `/station-admin/drivers/password`: hidden `_token`, `user_id`; required `password`, `confirm_password` min8/max72 autocomplete new-password; `Save password` submit. Passwords are not draft-persisted.
- [x] `StationController::setDriverPassword`: role+CSRF, password validation, refreshed token, RLS-scoped real driver-list lookup for session station before auth password update, flash+redirect; friendly errors. List failure returns[] and refresh error, never sample fallback. No active/inactive/edit/delete driver control exists.
- [ ] Live assignment/current-trip availability still requires dispatch backend; test isolation and revalidation of station membership for any future workflow.

#### Add driver — `/station-admin/drivers/add` (`add-driver.php`)

- [x] Real form POST `/station-admin/drivers`; CSRF hidden `_token`; no StationData fields. Station identity displayed from roleInfo.caption. Nonsecret old input restored through Session after validation failure.
- [x] Fields: first_name/last_name required max60; phone required09XXXXXXXXX or+639XXXXXXXXX; email required max254; vehicle Motorcycle with sidecar/Tricycle/Delivery van; plate required2–20 letters/digits/spaces/dashes; capacity required1–100 default15; password/confirm_password required8–72; verified required checkbox valueyes. Password show/hide toggle only affects presentation.
- [x] Buttons: `Back to drivers`, `Cancel`→drivers; `Create driver account` submit.
- [x] `StationController::addDriver`: role+CSRF, server validation, auth creates confirmed rider account; `Registry::registerDriver` calls trusted provisioning RPC `register_driver` with session station/admin; rollback deletes half-created auth account when provisioning fails; flash+redirect. This is existing real provisioning (admin RPC), not an ordering action. No invitation email is sent.

#### Customers — `/station-admin/customers` (`customers.php`)

- [x] Reads `sample.customers`; all4 metrics derived from fixtures (customer count, household/institution, total order count). Search `customer-search` filters browser table.
- [x] `Export CSV` exports visible rendered `station-customers` table including action text. Each row: `Details` opens `customer-{index}` dialog; `Message`→messages?customer=maria/luis/ana/learning-center by row position. Dialog displays sample name/type/barangay/phone/order count and `Close` button.
- [ ] No real station customer history, last-order/total-spend fields, classification edit, or live customer detail/history action.

#### Messages — `/station-admin/messages` (`messages.php`, `shared/messages.php`)

- [x] Uses `MessagingData::forRole('station-admin')`, not `StationData`. Always six Guintas fixture conversations even outside preview: guintas-maria/HMT-104 delivery; guintas-luis/HMT-105 pickup; guintas-ana/HMT-106 delivery; guintas-lito/HMT-107 delivery; guintas-emilia/HMT-109 pickup; guintas-learning-center/no order. Viewer is fixed `station:guintas`; each thread has two Nov2 2026 canned messages. Actual session station/customer IDs are unused.
- [x] Fields/actions: `chat-search` (name/order filter); JS-generated thread buttons; mobile Back to conversations; optional contextual `Order HMT-...` link→orders?order=id; textarea `chat-body` max2000; emoji toggle plus5 insertion buttons; send button; Enter sends/Shift+Enter newline; character count; unread dot and device-only seen state. Invalid `thread`/`customer` query shows unavailable warning then falls back; selecting thread updates URL `?thread=`.
- [x] Composer has no method/action, input name or CSRF, and requires JS. Send appends plain text to localStorage `hamtic-chat:v1:thread:{thread_id}` (last300 extra messages). Read marker `hamtic-chat:v1:read:{viewer}:{thread_id}`; storage events sync tabs on the same device only. Storage failures keep unsaved messages in memory with disclosure. Thread seed uses safe JSON flags; bodies are rendered through textContent.
- [ ] No Supabase read/write/polling, real message navigation badge, topic/unread filter, rate limit, station closed notice/next opening, reservation form/cards/accept/decline/reply/cancel/expire or read RPC. Reserved backend conversations/messages tables are not connected here.

#### Products — `/station-admin/products` (`products.php`)

- [x] Reads global `Products::all/get`, not StationData or DB. Default `?product=round`; invalid selector renders Product not found + `View catalogue`. All7 literal products rendered Available, badge “7 active products”. `Edit` links to same page?product=key#product-editor.
- [x] Editor draft key `station-product-v2-{product}`: hidden product; name required max80; price PHP required1–1000 step0.50; availability available/unavailable; description max250; `Save product` submit. Browser restore skips hidden product. Saving explicitly leaves catalogue prices unchanged.
- [x] Literal container policy repeats fee bands5/10/15. No add-product, deactivate action, pack text, returnable flag or image picker. Stored browser edits do not update the list or customer prices.

#### Schedules — `/station-admin/schedules` (`schedules.php`)

- [x] Reads `sample.days`; fixed Open until6PM badge and all dates. Weekly form key `station-weekly-hours`: for day index0–6 `open_i` required07:00; `close_i` required18:00; `status_i` open/closed (Sunday defaultclosed, othersopen); cutoff required17:00; `Save weekly hours` submit. One window/day only.
- [x] Temporary closure form key `station-closure`: reason maintenance/no-operations/other; starts required2026-11-04T13:00; ends required2026-11-04T16:00; notice required max200 with maintenance text; show_notice checked; `Save closure` submit. JS checks end>start. `Set no operations today` opens dialog.
- [x] `no-operations` dialog: form key `station-no-operations`, literal Nov2 2026; notice required max200 with no-operations text; `Save local notice` submit; `Close` dismiss. Shared draft feedback does not notify customers.
- [x] Upcoming closures literal Nov4 maintenance1–4PM, Nov8 no operations/all day. No remove/edit existing closure, second daily window, immediate close with optional end, reopen, next7 bookable-day preview, affected-order review action or shared availability calculation.

#### Settings: profile — `/station-admin/settings` (`settings-profile.php`)

- [x] Tabs link Profile/Service areas/Order rules. Draft key `station-profile`; name required max100 defaults caption; contact required Elena Reyes; phone required0917 555 0301 with permissive10–18-character phone pattern; email requiredhello@guintas.example; address required max250 “National Road, Guintas, Hamtic, Antique”; about max400 canned description; `Save profile` submit.
- [x] Sidebar literal Guintas/Hamtic/Antique and Registered; station name may be real session caption. `Manage hours`→schedules; `Service areas`→settings/service-areas.
- [ ] No persisted profile, explicit barangay selector, map pin/current-location control, coordinate validation or description read from DB. Contact-person field exists in UI and needs an explicit disposition in design.

#### Settings: service areas — `/station-admin/settings/service-areas` (`settings-service-areas.php`)

- [x] Same3 tabs. Reads sample.areas (4 only). Draft key `station-service-areas`; each `enabled_i` checkbox (first2 checked), `fee_i` PHP number required0–500 (Guintas0, all others10), `minimum_i` required1–20 default2; `Save service areas` submit. No map/reference barangay source.
- [x] This local per-barangay fee/minimum UI is inconsistent with the requested distance fee bands and defaults; do not promote these fixtures to production rules without reconciliation.

#### Settings: order rules — `/station-admin/settings/order-rules` (`settings-order-rules.php`)

- [x] Same3 tabs. Literal draft key `station-order-rules`; `max_containers`20 range1–100; `horizon`7 range1–30; `duplicate_window`30 range1–1440; `refresh`5 range1–60; `aging_points`5 range0–50; `aging_cap`25 range0–100; `urgency_asap`40/`urgency_soon`25/`urgency_later`10 each0–100; institution_bonus_enabled checked; institution_bonus10 range0–100; every number required. `Save rules` submit.
- [x] Literal preview U40+A10+C10=60, rule version1/sample configuration; no live preview recalculation. Prompt default institution bonus is OFF, while this fixture is ON. No max distance/fee-band editor/version history.

#### Sales report — `/station-admin/reports` (`reports-sales.php`)

- [x] Reads no effective sample keys (unused `$s` assignment). Tabs Sales/Orders/Deliveries. `Export CSV` browser-downloads `station-sales` table.
- [x] All literals: Nov1–2 2026; verified6,900/unverified450/completed totals6,900; two3,450 bars; daily23 completed each; collected3,450 then3,900; verified3,450 each; second state450 to verify; text collected7,350=verified6,900+450. No actual cash verification action.
- [ ] Missing date filter, server scoped measures, distinct delivery-fee reporting and print controls/style.

#### Order report — `/station-admin/reports/orders` (`reports-orders.php`)

- [x] No sample key; same tabs; `Export CSV`→rendered `station-order-report` daily summary.
- [x] Literal Nov1–2:50 placed/46 completed/4 cancelled/230 containers/92%; per day25/23/2/115/92%; Guintas30/60%, Poblacion20/40%; cancellations customer request3/outside hours1. No forms or row actions.
- [ ] Missing date/status/fulfillment/product breakdown filters and real scoped aggregate query/print.

#### Delivery report — `/station-admin/reports/deliveries` (`reports-deliveries.php`)

- [x] No sample key; same tabs; `Export CSV`→rendered `station-delivery-report` driver table. Juan/Carlo/Ben `View trip` links→08/07/06. `Manage active trips`→deliveries.
- [x] Literal Nov1–2:16 completed trips/46 orders/230 containers; Juan6/18/90/PHP2,700, Carlo5/14/70/PHP2,100, Ben5/14/70/PHP2,100; totals16/46/230/PHP6,900; each day8/23/115;2 follow-ups (unavailable1/address clarification1). No reconciliation inputs, approval or reattempt buttons.
- [ ] Missing date filtering, failed-attempt review, distance/travel-time metrics, persisted scoped totals and print.

#### Trip details — `/station-admin/trip` (`trip.php`)

- [x] Reads sample.trips; accepts known string `?trip=` else default08; no empty-list handling. Displays sample driver/stops/capacity/status. `Back to deliveries`→deliveries; `Review delivery report`→reports/deliveries.
- [x] Literals Nov2 2026 timeline10:00 dispatch/10:18 first completion/10:25 en route or10:50 returning or11:02 complete. Cash inferred as delivered fixture quantities×global round price plus PHP10 per delivered stop; fixed1.5km fee shortcut. No actual payments, travel distances or log entries.
- [ ] No live polling/map/rider distance, per-stop attempt details, fee mismatch review, cash-applied/tendered/change/empties verification, complete-order control, approve-reattempt or cancel. The reconciliation sentence is display-only.

### Shared navigation, dialogs and browser mechanics

- [x] Desktop sidebar links: Overview, Order queue, Deliveries, Pickup orders, Drivers, Customers, Messages, Products, Schedules, Reports, Settings. Mobile bottom links first4 plus `More`, which toggles sidebar and overlay; avatar→Settings; logo→public home; skip link→main content.
- [x] `Switch role` opens `role-switch` dialog; close icon dismisses;4 role links navigate to their workspace GET routes (live role checks still apply). Preview disclosure includes `View login`→login/station-admin. Role chooser prose always describes sample workspaces.
- [x] `Log out`: real user link `/logout?token={CSRF}`; preview link `/login/station-admin`; SweetAlert confirmation adds logged_out=1 before navigation. Existing AuthController logout route performs server logout when a real user is present. All pages share feedback toast/flash and `confirm-action` dialog with Cancel/Confirm/close.
- [x] `app.js` stores drafts at `hamtic-ui:form:{data-persist}`. Restores nonhidden/nonsecret fields, skips password/secret/token/file; serializes disabled fields out and checkbox/radio values as arrays. This is browser/device state, independent of Supabase and not per authenticated user.
- [x] Generic status state is `hamtic-ui:status:{pathname + sorted query}:{target_id}`. It changes only the selected element and restores only that page scope; no workflow validation/backend save/notifications. Confirmation does not capture cash or empties.
- [x] CSV exports use visible DOM rows, CSV-injection prefix protection, BOM/Blob download and toast “Sample table exported as CSV.” Exports include all table cells (including Action text) and do not fetch the complete dataset.
- [x] `head.php` loads SweetAlert2, app.js, messages.js and fulfillment.js globally. Generic filters operate on existing DOM only. `fulfillment.js` has no station dispatch logic: its station behavior is pickup badge/button synchronization; other behavior improves customer checkout draft mode/address/fee copy.

### Rider dependencies required for real station fulfillment

| Rider surface | Existing controls/data | What currently persists / missing dependency |
| --- | --- | --- |
| `/rider` trip | `RiderData`: name/station/trip/stop/next/completed/total; Trip08, Juan Reyes, Guintas, Maria Santos Purok2/near barangay hall,3 containers/PHP70/3 empties, next Juan Dela Cruz. Literal Nov2 date and Assigned status. `Call` toast explicitly unavailable; `Address` opens sample-location dialog + close icon. | RiderData remains unchanged outside preview; no assigned real trip/stop lookup, telephone link, station/customer coordinates or polling. |
| Rider cash/empties | Cash received number default100.00, minimum amountdue70, step0.01; change output computed by app.js; Mark delivered disabled for blank/nonfinite/insufficient received. Empties input0–20 default3 with minus/plus counters. | Inputs are outside a submitting result form; neither cash nor empties is saved or passed into status confirmation. Browser `Mark delivered` merely persists Delivered badge in localStorage. Station trip/cash report never receives it. |
| Rider delivery actions | `Mark delivered`→shared sample confirmation; `Report a problem`→`/rider/problem`; address dialog. | No mark_arrived fee decision, delivered/failed transaction, current order version, idempotency, active-trip location permission/explanation/sender or close-trip action. |
| `/rider/problem` | Reads sample trip/stop. Form `rider-problem`: required reason unavailable/address/containers/vehicle/other; required details; contact_attempted checkbox; `Save problem draft`. Back→rider; `Call station` unavailable toast. | Local draft only; no failed attempt/reason reaches owner; no reattempt approval workflow. |
| `/rider/history` | Reads sample.history: HMT101 Maria/3 delivered9:40, HMT099 Luis/2 delivered8:50, HMT097 Ana/2 failed unavailable8:10, HMT095 Pedro/1 delivered7:45. Filter all/Delivered/Customer unavailable; native details toggles per attempt. | No real trip/attempt history or station synchronization; stats derive fixture history only. |
| `/rider/account` | sample name/station/contact/vehicle/plate, literal JR/Active. Browser profile form full_name/phone/email + Save profile, Log out. | Existing rider auth account can be provisioned by owner, but account details and assignments remain sample UI. Only supporting operational rider work is in requested scope. |

### Completion checklist for the later implementation design

- [ ] Preserve real driver provisioning, password reset, role/auth and registry isolation while replacing fake driver availability.
- [ ] Replace all sample/literal station reads above outside preview; keep preview fixture behavior and DOM contracts for regressions.
- [ ] Connect every local draft/status/chat action to a CSRF-protected server action/RPC; real forms must remain functional without JS.
- [ ] Include controls absent today: product creation/full metadata, pins, versioned distance rules, real schedules/reopen, order cancellation/holds/overdue, dispatch preview/capacity/SSTF, trip/attempt results, station cash verification/completion, classification, reservation decisions and reports filters/print.
- [ ] Canonical statuses, fee/cash definitions and settings must follow the system plan; preview “Picked up”, per-barangay fees/minimums, institution-bonus ON and simplified price/distance calculations are existing UI artifacts, not approved rules.
- [ ] Treat server-returned empty lists as first-class states; current order/trip/chat default-selection code assumes samples are nonempty.
