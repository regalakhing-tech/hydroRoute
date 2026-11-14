# Shared UI contract

Use `use App\UI\UI as U;` inside each PHP view. Each screen template is a partial; the root-owned layout handles HTML/head/body, sidebar, mobile navigation, script and CSS. You receive `$data['screen']`, `$data['role']`, `$data['sample']`, and `$e` escaping closure. Keep view logic simple; put sample arrays in your owned Data class.

## Helper signatures (root implements these)
```php
U::e(string|int|float|null $value): string
U::icon(string $name, string $class = 'size-5'): string
U::btn(string $label, string $href = '', string $variant = 'primary', string $icon = '', array $attrs = []): string
U::badge(string $label, string $tone = 'blue'): string
U::field(string $label, string $name, string $value = '', string $type = 'text', array $attrs = []): string
U::select(string $label, string $name, array $options, string $selected = '', array $attrs = []): string
U::textarea(string $label, string $name, string $value = '', array $attrs = []): string
U::table(array $headers, array $rows, array $attrs = []): string // cell HTML trusted, escape sample strings
U::heading(string $title, string $subtitle = '', string $actions = ''): string
U::tabs(array $links, string $active): string // links = [href => label], active = current href
U::avatar(string $initials, string $class = ''): string
U::money(float|int $amount): string
U::productImage(string $class = 'h-20 w-20'): string
```

Buttons: variant primary, secondary, ghost, danger. Attributes array supports any authored HTML attrs, escaped automatically. Default button type button. Set type submit explicitly for forms.

Icons: droplet, home, pin, orders, truck, users, user, bottle, calendar, chart, settings, bell, search, chevron, arrow-right, arrow-left, plus, minus, check, clock, phone, mail, eye, menu, close, logout, shield, file, activity, filter, download, alert, edit, store, refresh, credit, help.

Reusable classes implemented with Tailwind @apply:
- `panel` white rounded-xl border border-line; use padding `p-5 md:p-6`.
- `btn btn-primary/secondary/ghost/danger`, `field`, `label`, `muted`, `section-title`, `data-table`, `tab-link`.
- Colors: ink, muted, water (cyan action), mist (pale blue page), line (fine blue border), foam (pale cyan panels).
Use ordinary Tailwind utilities for layouts, spacing, type and responsive behavior. Manrope headings, Source Sans 3 body.
Content wrapper max width 1440px. Side inspector layouts use `grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]`. Data tables horizontally scroll within their own wrappers.

## Local interaction conventions
- `<form data-local-form data-success="Saved in this browser.">` validates HTML constraints and saves non-password values locally, then shows feedback.
- Add `data-persist="unique-form-key"` to restore that form's own values on reload.
- `data-search="table-id"` on input filters rows of matching element ID. Put `data-search-row` on rows. Use `data-empty` hidden message in table wrapper.
- `data-filter-target="table-id"` on select filters rows via `data-filter-value` on rows; its option value `all` clears filter.
- `data-toast="Message"` on buttons shows explicit local UI feedback. Do not make a false backend success claim.
- `data-dialog-open="dialog-id"` and `data-dialog-close` for native dialog. Root JS manages focus.
- `data-step="1"` or `data-step="-1"` and `data-counter="input-id"` buttons adjust numeric inputs.
- `data-export="table-id"` downloads visible rows as CSV.
- `data-status="Delivered"` + `data-status-target="element-id"` updates a sample badge after a confirmation dialog.
- Links use actual app URLs, never # except real on-page anchors.
- Use `data-demo-action` for UI-only actions that cannot truthfully run, with `data-toast` explaining what happened.
- No auth calls, tokens, password storage, actual email/SMS or station database writes.

## Route/filename catalogue
Customer prefix /customer:
overview.php => /customer; stations.php => /customer/stations; orders.php => /customer/orders; addresses.php => /customer/addresses; notifications.php => /customer/notifications; account.php => /customer/account; station.php => /customer/station; checkout.php => /customer/checkout; tracking.php => /customer/tracking.

Station prefix /station-admin:
overview.php => /station-admin; orders.php => /station-admin/orders; deliveries.php => /station-admin/deliveries; drivers.php => /station-admin/drivers; customers.php => /station-admin/customers; products.php => /station-admin/products; schedules.php => /station-admin/schedules; reports-sales.php => /station-admin/reports; settings-profile.php => /station-admin/settings; trip.php => /station-admin/trip; add-driver.php => /station-admin/drivers/add; settings-service-areas.php => /station-admin/settings/service-areas; settings-order-rules.php => /station-admin/settings/order-rules; reports-orders.php => /station-admin/reports/orders; reports-deliveries.php => /station-admin/reports/deliveries.

Rider: trip.php => /rider; history.php => /rider/history; account.php => /rider/account; problem.php => /rider/problem.
Super Admin: overview.php => /super-admin; applications.php => /super-admin/applications; stations.php => /super-admin/stations; drivers.php => /super-admin/drivers; activity.php => /super-admin/activity; account.php => /super-admin/account; application.php => /super-admin/application.

Data classes: final class CustomerData/StationData/RiderData/RegistryData in namespace App\UI with public static function all(): array. Root calls only the selected role's data loader. Super Admin never receives other role arrays.

Date sample November 2, 2026, Hamtic, Antique. PHP money uses PHP currency prefix. Stations and people are fictional examples. Shared imagery /assets/images/hero-water.png and /assets/images/water-jug.png.

