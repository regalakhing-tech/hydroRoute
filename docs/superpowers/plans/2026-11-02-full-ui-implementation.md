# Hamtic Water Full UI Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans and dispatching-parallel-agents for the independent role templates. Execute in the current workspace because it is not a Git repository.

**Goal:** Implement the approved mockup UI across all four roles, with four dedicated login pages.
**Architecture:** Explicit PHP routes render shared layout and independent role templates using reusable UI helpers. Browser-local JS provides preview interactions; compiled Tailwind provides styling.
**Tech Stack:** Plain PHP 8.4+, Tailwind CSS 4.3.3, vanilla JavaScript, generated PNG assets.

## Global constraints
- UI only; no Supabase mutations or authentication claims.
- Use the existing project and preserve configuration and health endpoint.
- All sample records are fictional; never store passwords.
- Super Admin renders only registration metadata; no order counts, orders, payments or revenue.
- Tailwind styling must be compiled to public/assets/css/app.css.
- Four login URLs: /login/customer, /login/rider, /login/station-admin, /login/super-admin.
- Screens follow the saved light-blue/cyan mockups and support keyboard and mobile use.
- Do not change files assigned to another implementer.

## Task 1 — shell, routes, shared UI and assets (root)
- [x] Add tests/ui-smoke.php and observe missing role routes fail.
- [x] Add app/UI/UI.php helper contract in docs/ui-contract.md.
- [x] Add app/UI/Pages.php route catalogue and app/Controllers/UIController.php.
- [x] Build layouts/app.php, auth/login.php, auth/register.php, auth/recovery.php, shared/home.php, shared/register-station.php.
- [x] Generate hero-water.png and water-jug.png; save locally and allowlist their routes.
- [x] Install pinned Tailwind CLI and compile resources/css/app.css.
- [x] Implement public/assets/js/app.js local-only interaction handlers.

## Task 2 — customer templates
- [x] Implement app/Views/customer/*.php for all nine screens using docs/ui-contract.md.
- [x] Provide sample data only in app/UI/CustomerData.php.
- [x] Run php -l on each owned file and review against customer mockups.

## Task 3 — station admin templates
- [x] Implement app/Views/station-admin/*.php for all fifteen screens using docs/ui-contract.md.
- [x] Provide sample data only in app/UI/StationData.php.
- [x] Run php -l on each owned file and review against station mockups.

## Task 4 — rider and registry templates
- [x] Implement app/Views/rider/*.php (four) and app/Views/super-admin/*.php (seven).
- [x] Provide sample data in app/UI/RiderData.php and app/UI/RegistryData.php.
- [x] Run php -l on owned files; registry pages must exclude operational and financial fields.

## Task 5 — integration and review (root)
- [x] Build Tailwind after all templates land.
- [x] Run php tests/ui-smoke.php against the local server.
- [x] Test all internal links, valid/invalid asset routes and existing health endpoint.
- [ ] Browser screenshot QA unavailable: browser host and native pipe could not connect. DOM behavior checks pass; actual visual layout still needs manual review.
- [x] Update README.md and docs/ui-implementation.md with routes, commands and UI-only limitations.


## Verified outcome

Implemented 44 app pages including 35 workspace screens and four dedicated logins. Tailwind build passes. All 64 PHP files lint. HTTP smoke: 44 pages and 48 links/assets pass. DOM: 44 pages and 248 assertions pass. Final code review has no remaining Critical or Important findings. No Git repository or deployment was created.
