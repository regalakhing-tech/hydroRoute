# Customer UI implementation

Implemented all nine customer templates: overview, stations, orders, addresses, notifications, account, station, checkout, and tracking. Added `app/UI/CustomerData.php` with fictional Hamtic station, order, address, notification, and progress fixtures dated November 2026.

The overview, station, checkout, and tracking local reference PNGs were inspected before implementation. Layouts follow their pale cyan surfaces, bordered white workspaces, product images, order inspector, and vertical status timeline. Templates use shared UI helpers and responsive Tailwind columns, wrapping flex rows, locally scrolling tables, labeled fields, and accessible quantity controls.

Interactions include station/order search and status filters, real route links, station quantity inputs and counters, GET checkout quantities, validated browser-local account/address/preferences/order-draft forms, and explicit preview-only contact feedback. Each persisted form uses a unique key. Order saving clearly says no order was sent. Password storage and external service calls are absent.

Verification: all nine templates and the data class passed `php -l`. A source scan found no text encoding artifacts after correction. Files remain within the assigned ownership scope.

Integration notes: shared `UI.php`, layout, stylesheet, and JavaScript were not yet available at this verification point, so browser rendering remains for root integration QA. Root confirmed support for station `data-order-builder`, numeric `data-unit-price`, and `data-order-total` hooks. Combined zero checkout quantity normalizes to one round refill; individual quantities clamp to 0–20. No live backend behavior is claimed. Saved form details do not mutate the sample address cards or records.

## Review addendum

Station fixtures now have stable IDs, individual operating hours, availability, and next-availability messages. Every station card retains its selected ID; station details and checkout validate it against fixture IDs. Closed and maintenance stations expose their own details and availability notices, with no ordering controls. Direct checkout requests for unavailable stations also stop before rendering the draft form. Unknown IDs and malformed array query parameters show a safe empty state with navigation back to the list.

Every order table and notification link now retains its order ID. Tracking validates the ID and renders the selected order's station, quantity, total, date, status, timeline stage, and matching history. The station profile uses its fixed three-column layout only at the xl breakpoint to avoid intermediate-width overflow.

Verification after these changes: all 10 owned PHP files passed lint. A direct PHP render harness using the actual shared UI helper passed 24 checks covering every station's identity/hours, builder and checkout availability, all order identities/stations/totals/statuses, unknown and malformed references, and rendering of the remaining six templates. Browser layout QA remains with root integration.
