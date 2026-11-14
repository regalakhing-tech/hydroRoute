# Product catalogue and pricing update

Implemented the confirmed three-product catalogue: water bottle PHP 12, blue faucet container refill PHP 20, and round container refill PHP 20. This remains a local UI preview; no station database, authentication, or Supabase changes were made.

## Files

- `app/UI/Products.php`: shared names, integer PHP prices, asset paths, unit labels, returnable metadata, allowlisted product lookup and validated basket quotes. Defaults to three round refills only when no recognized quantity selector exists. Explicit zero quantities stay empty. Arrays and malformed quantities become zero; integer quantities clamp to 0–20 per product.
- `app/UI/UI.php`: product image helper accepts an optional product ID while preserving existing one-argument calls and the round image default.
- `app/Views/customer/station.php`: three product images, unit prices, independent quantity steppers and live basket total.
- `app/Views/customer/checkout.php`: server-calculated mixed basket, itemized product thumbnails/subtotals, bottles versus containers, returnable-only expected empties and a safe empty basket chooser. Versioned draft keys include station and canonical round/faucet/bottle quantities, so returns and notes restore only for the same basket.
- `app/Views/customer/stations.php`: catalogue-backed overview pricing replaces the former slim product and PHP 30 copy.
- `app/Views/station-admin/products.php`: three product rows, allowlisted product selection and independent versioned local editor keys (`station-product-v2-{id}`). Old PHP 30 local drafts cannot replace the approved starting price.
- `app/UI/CustomerData.php`, `app/Views/customer/tracking.php`: round refill examples use PHP 20 (three PHP 60; two PHP 40).
- `app/UI/RiderData.php`, `app/Views/rider/trip.php`: current stop PHP 60, amount-due/minimum derived from its fixture, and initial PHP 100 cash change PHP 40.
- `app/Views/station-admin/orders.php`, `app/Views/station-admin/trip.php`: current per-order and trip cash calculations use the catalogue round price. Priority scores and historical report aggregates are preserved.
- `tests/product-catalog.php`: 14 catalogue assertions.
- `tests/ui-dom.mjs`: three-product live totals, mixed checkout thumbnails/units/empties, bottle-only returns, zero/invalid baskets, stale product draft isolation and invalid product references. Rider live PHP 150 change is PHP 90.

## Verification

- Ran `node tests/product-pricing-smoke.mjs` before implementation: failed as expected on missing Water bottle.
- Linted all 13 changed/new PHP files: passed.
- `php tests/product-catalog.php`: 14 assertions passed.
- `node tests/ui-dom.mjs` against `http://127.0.0.1:8001`: 44 page checks and 266 assertions passed. Includes saving an actual refill draft, transferring its local storage to a bottle-only checkout (zero returns), and reopening the same refill basket (custom returns and notes restored).
- Searched app templates/UI for obsolete `PHP 30`, `money(30)`, `*30` and `slim`: no matches.

Product PNG generation, public asset allowlisting, the separate HTTP pricing/asset smoke, Tailwind build and browser visual review are handled by the coordinating task.
