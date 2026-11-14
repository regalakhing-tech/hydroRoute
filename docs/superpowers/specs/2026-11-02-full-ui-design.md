# Hamtic Water full UI design

The approved 39 mockups in Downloads are the visual reference. The user has requested immediate implementation of all UI in the existing plain PHP app with Tailwind, plus a dedicated login for Customer, Rider, Station Admin and Super Admin. This authorizes proceeding without another design approval.

## Visual direction
Pale water blue #E9F8FE, white #FFFFFF, cyan #008BAD, deep ink #073D59, muted blue #58758A, fine blue borders #D8EAF1. Manrope headings and Source Sans 3 body. Match the screenshots' generous white workspaces, cyan navigation selection, restrained rounded forms and clear data tables. Public/auth pages use newly generated water-jug photography; product thumbnails use a transparent generated jug. Desktop management uses a 248px sidebar. Rider pages use large touch controls and fixed bottom navigation. All pages adapt to 375px.

## Scope
Shared: homepage, role login chooser, four dedicated login pages, customer signup, recovery, station registration, role UI preview links.
Customer: overview, station finder, orders, addresses, notifications, account, station details, checkout, tracking.
Rider: trip, history, account, delivery problem.
Station Admin: overview, queue, deliveries, drivers, customers, products, schedules, sales/orders/delivery reports, profile/service-area/order-rule settings, trip detail, add driver.
Super Admin: registry, applications, stations, driver registrations, activity, account, application review. Registration metadata only; no order or financial data.

## Interaction
UI-only sample data; no authentication or database mutations. Login forms validate locally and clearly identify themselves as a UI preview. A separate preview action opens each workspace. Google actions explain that identity integration is pending. Filters, search, drawers, dialogs, quantities, totals, local form state, status actions, CSV exports and responsive navigation work in-browser. No password is saved. Save actions clearly say saved in this browser. App copy stays concise and operational.

## Architecture
Keep explicit PHP routes and the asset allowlist. Reuse a shared layout and UI helpers, role-specific templates and role-specific sample data. Compile Tailwind locally to the existing public CSS file. Serve local images via explicit safe asset entries. No Supabase changes or credentials in client code.

## Validation
PHP lint all source. HTTP smoke test all declared pages, four role logins and asset types. Crawl internal links; verify unknown paths and nonpublic files stay unavailable. Verify Super Admin rendered HTML contains no order/customer/financial fixture data. Browser review desktop and mobile plus representative interactions.

