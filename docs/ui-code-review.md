# UI code review — November 2, 2026

Scope: read-only review of all role templates, shared layouts/helpers, role fixtures, routing and shared JavaScript against `docs/ui-contract.md` and the approved design specification. App source was not edited by this reviewer. Findings below describe code as inspected; root may subsequently resolve them. Browser automation is unavailable in this environment, so reproductions below are code-derived browser steps, not claims of completed browser testing. Root owns lint and HTTP smoke verification.

## Critical

None found. Registry rendering loads only RegistryData, with no order/customer/financial fixture arrays. UI forms disclose preview behavior and do not call authentication or database services. Helper-generated attributes and fixture text are escaped. Reviewed explicit routes correspond to existing templates.

## Important findings reported to root

1. **Status persistence crosses entity boundaries.** `public/assets/js/app.js:136` and `:149` key statuses by pathname and target ID, excluding selected query parameters. `app/Views/super-admin/application.php:3` reuses `application-status` for every application; station queue similarly reuses `selected-order-status`. Reproduce: approve REG-026, navigate to `/super-admin/application?ref=REG-025`; it restores Approved for the second application. Likewise change HMT-104 and open HMT-105. Scope persistence to the selected entity (explicit ID or canonical query identity) on both write and read.

2. **Active filter also includes Inactive drivers.** `public/assets/js/app.js:64` compares filter selections using substring inclusion. In `app/Views/super-admin/drivers.php:3`, choosing Active matches both `active` and `inactive`. Use exact equality for the authored single-value `data-filter-value` attributes; keep substring matching for free-text searches.

3. **Most empty-search messages never appear.** `public/assets/js/app.js:69`–`:71` looks only inside the target or table's immediate wrapper and toggles the `hidden` class. Many templates use the native `hidden` attribute, which remains set; others place the empty message outside that wrapper. Reproduce: type an impossible search on customer orders, station finder, registry applications, or registered stations. All records disappear without the authored empty state. Find the proper component wrapper and update both visibility mechanisms, or standardize explicit empty-state target hooks.

4. **An old checkout draft overrides new hidden order quantities.** `public/assets/js/app.js:23`–`:31` restores every named non-sensitive input, including hidden fields. `app/Views/customer/checkout.php:11` persists per station, while its hidden quantity and displayed summary derive from URL parameters. Reproduce: save a draft for three round refills, return to the station, select five and continue. The rendered summary says five / PHP 150 but restoration resets hidden quantity to three. Preserve server-derived immutable fields during restoration, or scope the draft to its complete order identity. Preserve round/slim breakdown as well if the saved draft is meant to describe the order.

5. **Cross-field validation cannot recover when the other field is corrected.** `public/assets/js/app.js:167` marks confirm-password invalid and clears only when confirm-password is edited; `:175` similarly marks closure Ends invalid and clears only on Ends edits. Reproduce: submit mismatched passwords, then correct only Password to match; or submit a closure whose start follows its end and correct only Starts. Native invalidity blocks the submit event, so the code that would clear the error is never reached. Clear/recompute the relevant custom validity on input in either member of each pair.

## Minor findings

- `app/Views/station-admin/trip.php:3` uses the same ten-container, three-stop fixture for Trips 06, 07 and 08, changing only driver/area/completion labels. The source list in `app/Views/station-admin/deliveries.php:5` gives Trip 08 twelve containers and Trip 07 eight containers, two stops and Returning status. Opening those trips contradicts their list row. Supply consistent per-trip fixtures.
- `app/Views/station-admin/schedules.php:4` and `:5` both generate a textarea with `id="notice"`. The dialog label can resolve to the background form's field. Give each textarea a unique ID while retaining its form-local name.
- `app/Views/station-admin/deliveries.php:1` accepts an array-valued `tab` query and concatenates it on line 3. `/station-admin/deliveries?tab[]=ready` produces an array-to-string warning. Validate against the three allowed string values before building the active tab URL.

## Earlier findings resolved during review

- Customer station cards and order links initially all opened hard-coded Guintas / HMT-104 details. Current customer code carries stable station/order IDs, rejects unknown selectors, and suppresses ordering for unavailable stations, including direct checkout requests.
- Rider contact links initially opened dialers for fictional phone numbers. Implementer replaced them with explicit preview feedback, as reported by root.
- Eight rider/registry source files originally contained CP1252 bytes under a UTF-8 document declaration. A subsequent strict UTF-8 decoding scan of every `app/**/*.php` file passed with no errors.
- Customer station detail's earlier fixed three-column layout activated too early. Current code delays it until XL and uses `minmax(0,1fr)` for the flexible column.

## Review limitations

The approved screenshot set was not re-compared in this code review. Browser layout, focus behavior and downloadable CSV behavior still need runtime verification when a browser is available. Static code confirms password/token-like field names are excluded from browser persistence, native dialogs are used, and filters combine search with selection. Preview-only backend and authentication absence is intentional and is not a defect.

## Resolution addendum — reviewed after fixes

All five Important findings and all three Minor findings above are resolved in the current source. No remaining Critical or Important issues were found in this follow-up code review.

- Shared JavaScript now builds `pageScope` from the pathname and sorted query parameters, and uses it consistently for status writes and restoration. Different selected orders/applications no longer share the same stored status.
- Authored `data-filter-value` uses exact equality. Empty-state discovery climbs to the nearest containing wrapper, and visibility changes update both the native `hidden` property and CSS class.
- Form restoration skips hidden inputs, preserving checkout station/quantity fields rendered from the current request.
- Input in either password field or either closure-date field clears the pair's custom validity, allowing corrected values to reach validation again.
- Trip lists and trip details now derive loads, stop counts and status from the same per-trip StationData fixtures. The no-operations dialog has the unique `no-operations-notice` textarea ID. Delivery tabs accept only an allowlisted string value before concatenation.

Verification evidence: this reviewer re-read the changed JavaScript and station templates. Root reports its DOM suite passed for 44 pages with 248 assertions, including the five reported interaction cases; this reviewer did not duplicate unchanged tests. Visual browser verification remains unavailable and is not implied by this addendum.
