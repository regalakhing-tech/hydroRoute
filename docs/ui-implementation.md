# Hamtic Water UI

The approved light-blue/cyan mockups are implemented in the existing plain PHP application. Tailwind CSS is compiled to a local stylesheet. Two built-in generated images are stored in `public/assets/images/`; their prompts are recorded in `docs/assets-*.md`.

## Open locally

```powershell
php -S 127.0.0.1:8000 -t public public/router.php
```

Open http://localhost:8000. If port 8000 is already used, choose another local port such as 8001. No Supabase configuration is needed for the UI preview. Node.js is needed only to rebuild CSS and run DOM tests.

## Four dedicated login pages

| Role | Login | Workspace |
| --- | --- | --- |
| Customer | /login/customer | /customer |
| Rider | /login/rider | /rider |
| Station Admin | /login/station-admin | /station-admin |
| Super Admin | /login/super-admin | /super-admin |

The /login page lets you choose a role. Each dedicated form has its own role copy and preview link. Email/password, Google login, signup and recovery are visual flows; no authentication request is sent. Passwords are never saved in local storage.

## Included screens

- Public: homepage with station search, role chooser, four logins, customer signup, recovery, station registration and preserved thesis page.
- Customer: overview, station finder, order history, addresses, notifications, account, selected station details, checkout draft and selected order tracking.
- Rider: current trip, delivery history, account and problem report.
- Station Admin: overview, priority queue, deliveries, drivers, customers, products, schedules/closures, sales/orders/delivery reports, station profile/service areas/order rules, selected trip and add driver.
- Super Admin: registry overview, applications, registered stations, driver registrations, registration activity, account and application review.

Super Admin pages load only registry fixtures. They receive no customer transactions, order counts, orders, payment or revenue data. This separation is a UI boundary, not production authorization.

## Preview interactions

The product catalogue now includes a water bottle at PHP 12, a blue faucet container refill at PHP 20, and a round container refill at PHP 20. Checkout supports mixed baskets and counts empty returns only for refillable containers. Product images are local transparent PNGs; prompts are recorded in `docs/product-image-prompts-2026-11-02.md`.

Search and status filters, visible empty states, report/registry CSV export, native dialogs, mobile sidebar, password visibility, container quantities and totals, rider cash change, validation, local form drafts and record-scoped status previews are implemented.

Saved preview changes stay in this browser; they do not update a database or notify anyone. Refreshing a page restores editable form fields. Server-derived station and quantity fields are preserved from the selected order. Status changes are scoped to the current record. Fictional contact buttons show preview feedback instead of dialing a sample number.

Unavailable stations show their own status and reopening information and cannot enter checkout. Unknown station and order references display a safe empty state.

## Development

```powershell
npm ci
npm run build:css
npm run dev:css
```

Source styles: `resources/css/app.css`. Generated stylesheet: `public/assets/css/app.css`. Tailwind scans PHP views and shared UI code; rebuild after changing utility classes. Commit/ship the generated CSS so PHP hosting does not depend on a browser CDN.

PHP page catalogue: `app/UI/Pages.php`. Shared helpers: `app/UI/UI.php`. Layouts: `app/Views/layouts/`. Role fixtures: `app/UI/*Data.php`. Browser interactions: `public/assets/js/app.js`.

Google Fonts provide Manrope and Source Sans 3 with local system fallbacks.

## Verification

Start the PHP server, then run:

```powershell
php tests/ui-smoke.php http://127.0.0.1:8000
node tests/ui-dom.mjs http://127.0.0.1:8000
```

The HTTP suite checks all 44 app pages, internal links/assets, dedicated login markers, registry data boundaries and nonpublic file access. The DOM suite checks page structure and interactive behaviors including exact filtering, empty states, quantity/price calculation, cash change, local drafts, per-record status isolation, password handling, validation recovery and mobile navigation. PHP source files are linted separately.

Browser automation was unavailable during implementation. Screenshots and actual browser layout at desktop/mobile widths remain a manual visual review step. DOM tests do not simulate browser layout.

## Backend work

Supabase authentication, Google OAuth configuration, role authorization/RLS, persistent station records, real orders, driver invitations and notifications remain separate backend work. The existing Supabase transport/configuration and health endpoint are preserved. Do not treat the public workspace previews as protected production pages.

