# Product catalogue review

Reviewed the shared catalogue, product image helper, mixed checkout calculations, station product editor, current customer/rider totals, and station order/trip calculations against the approved PHP 12 / PHP 20 / PHP 20 prices. No full test-suite rerun; the coordinating task handles integration verification.

## Verdict after fix review

No unresolved important findings in the reviewed scope. Re-read the corrected checkout and regression without rerunning the unchanged full suite. Checkout now keys drafts by version, validated station, and all three canonical quote quantities (`customer-checkout-v2-{station}-{round}-{faucet}-{bottle}`). Different baskets cannot collide, omitted zero selectors and query ordering produce the same scope, and legacy station-only drafts are ignored.

The new regression saves a real refill draft with custom returns and notes, verifies a bottle-only basket retains zero returns and separate notes, then verifies the same refill basket restores its custom values even with reordered query selectors. This directly covers the originally reproduced defect. The implementer reports 44 page checks / 266 DOM assertions and 14 catalogue assertions passing; final integration verification remains with the coordinator.

## Resolved finding

**Medium, resolved: checkout restored an empty-container count from a different basket.** The original `app/Views/customer/checkout.php` used the station-only persistence key `customer-checkout-{station}`. `public/assets/js/app.js:26-34` restored every editable named field, including `returns`, while deliberately preserving the server-calculated hidden product quantities. After saving a three-refill draft, opening a four-bottle checkout therefore displayed three empty containers despite the current basket requiring zero. The inverse transition restored zero for a refill basket. The same issue affected different mixed baskets.

Targeted DOM reproduction against the running app: GET `/customer/checkout?bottle=4`, seed `hamtic-ui:form:customer-checkout-guintas` with `{"returns":"3","round":"3","quantity":"3"}`, then execute the current app script. `#return-quantity` changes from `0` to `3`. The existing stale-checkout-draft regression covers hidden station/quantity and an editable note, but does not cover returns.

Resolution: checkout drafts are scoped by canonical basket identity, with regression coverage described above.

## Other reviewed behavior

Catalogue prices, integer bounds, explicit zero baskets, array quantity/product selectors, returnable-only server quote counts, itemized subtotals, product identity/image fallback, and independent versioned product editor draft keys were consistent with the requirements. Current customer/rider sample totals and station per-order/trip price calculations use PHP 20 consistently in the reviewed files. No additional important defect found in this scope.
