# Messaging, fulfillment and auth migration implementation plan

**Goal:** Add customer/station Messenger-style inboxes, pickup/delivery UI, and locally verified Supabase migration files.
**Architecture:** PHP templates, isolated feature JavaScript modules, existing Tailwind compilation, local browser previews, PostgreSQL migrations with RLS.
**Constraints:** UI only now; no live Supabase calls, no passwords/tokens saved; no Super Admin or rider message access; preserve product prices and current pages. No Git repository.

- [x] Task 1 Root: write shared contracts, verify missing routes, install pinned CLI/PGlite, add routes/nav/assets and feature script loading.
- [x] Task 2 Messaging worker: MessagingData, shared Messenger-style template, role wrappers, messages.js and focused DOM tests.
- [x] Task 3 Fulfillment worker: checkout choice, pickup tracking/history, station pickup queue and dispatch filtering, fulfillment.js and focused DOM tests.
- [x] Task 4 Database worker: CLI-generated auth/access, fulfillment and messaging migrations, PGlite/RLS tests, setup documentation.
- [x] Task 5 Root: integrate Tailwind and generic form/filter changes, run all checks, review and resolve findings, update docs, leave preview available.

File ownership and feature hooks: docs/messaging-fulfillment-contract.md.

