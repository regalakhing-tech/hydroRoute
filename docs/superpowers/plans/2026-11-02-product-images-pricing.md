# Product images and prices update

User-approved products: clear water bottle PHP 12, blue container with faucet refill PHP 20, and existing round container refill PHP 20. Reference photos are in the conversation. Preserve the light-blue/cyan UI and round-container image.

- [x] Generate separate transparent product PNGs for the clear bottle and blue faucet container; save to public/assets/images/water-bottle.png and faucet-container.png.
- [x] Use a single PHP product catalogue for labels, prices, image paths, returnable-container metadata and validated quantity calculations.
- [x] Show all three products on station ordering and station-admin product pages; replace the old slim product with the faucet product.
- [x] Support mixed checkout baskets and totals, itemized product thumbnails, correct unit labels and returnable counts.
- [x] Update current sample round-refill totals in customer and rider screens and station per-order calculations. Preserve priority scores and unrelated historical report aggregates.
- [x] Add regression checks for product prices, mixed baskets, invalid quantity selectors and live totals; run existing PHP/DOM/HTTP checks.
- [x] Rebuild Tailwind, verify local PNG assets and keep app running for the user.

UI-only scope, no Supabase/schema/auth changes. No new design approval is needed: the user confirmed the reference direction and exact prices.

