# Fulfillment UI implementation

Checkout now offers Delivery/Pickup icon radio cards with delivery default and safe query parsing. Pickup displays the chosen station and opening hours, changes scheduling/payment/review copy, and disables delivery address fields and their required state. Delivery retains its canonical versioned basket draft key; pickup uses the same canonical basket key with a `-pickup` suffix. Product quote totals and prices are unchanged, and no additional fee is added.

HMT-098 is a picked-up customer sample with a pickup-only timeline and cash-on-pickup details. Station tuple index 7 distinguishes methods; HMT-105 and HMT-109 are pickups. Station orders expose method/filter links, and the pickup queue has searchable statuses plus confirmed local Ready for pickup / collection actions. Collection requires Ready for pickup. Completed collection disables both actions. Delivery ready and selected lists exclude pickups; selected delivery load is computed as 3 / 15.

The feature module loads after app.js and handles only fulfillment controls and queue action state. All actions remain local previews. There are no Supabase/network mutation calls.

## Verification

- Baseline `node tests/fulfillment-dom.mjs` failed before implementation with `Checkout offers pickup choice`.
- All nine owned PHP files passed PHP lint.
- Existing `node tests/ui-dom.mjs` passed 44 page DOM checks / 267 assertions on the final worker run before shared route integration.
- Focused suite covers mode switching, disabled/required address state, FormData omission, cash/location copy, scheduled validation, unchanged mixed total, local pickup restore and basket isolation, invalid array mode, pickup tracking, delivery exclusion, queue action progression and safe invalid array references.
- Final focused run currently fails at the shared serializer dependency: pickup local draft still includes the disabled address until root updates app.js. Pickup route and combined filter integration also remain root-owned.

- Independent HTTP render checks passed for HMT-098 pickup tracking (no rider/out-for-delivery content) and delivery queue/selected draft pickup exclusion with 3 / 15 load.
- Direct PHP pickup partial render plus the shared confirmation dialog passed Ready for pickup -> Picked up progression and collection gating.
- `node --check` passed for the feature module and focused test.

## Limits

This is a browser-local sample, not a backend order workflow. Drafts/statuses cannot establish real station confirmation, availability or payment. No browser screenshot verification was performed by this worker; responsive structure uses shared existing layouts and scrollable tables.


## Final integration verification

Root integrated the live routes, navigation, message entry buttons, icons and deferred modules. Guintas now has six own-customer threads with names matched to the order/customer fixtures. The focused live messaging suite and additional persistence/read/focus regressions pass. Root fixed the seen-marker regression using ordered read watermarks, preserves unsaved in-memory messages across further sends/storage events, and restores keyboard focus after list refresh or mobile thread selection.

The combined UI suite loads app.js, messages.js and fulfillment.js and passes 47 pages / 281 assertions. HTTP checks pass for 47 pages and 56 local links/assets. Pickup switching, conditional address requirements, saved draft isolation, collection actions and dispatch exclusion also pass. Browser screenshots remain unavailable. Messages still stay on this device; no actual Supabase or remote messaging connection exists.