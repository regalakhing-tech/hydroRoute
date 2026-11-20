# Supabase database

HydroRoute uses one shared hosted Supabase project for every Hamtic station (project ref `ftcgurrxmvznwxwbnrrx`, region Tokyo). All business rules live in PostgreSQL: prices, fees, order status changes, capacity, permissions and audit trails are enforced by `security definer` RPCs and row-level security, never by the browser. The PHP app calls these RPCs with the signed-in user's token; service credentials stay on the server.

Application role names in SQL are `customer`, `station_admin`, `rider` and `super_admin` (UI routes use hyphens: `/station-admin`, `/super-admin`). The database role `authenticated` is a different thing from these application roles.

## Migrations

Apply in file-name order. Each file is wrapped in `begin; … commit;` and replaces earlier functions with `create or replace`, so a file applies fully or not at all.

| File | What it adds | Hosted |
|---|---|---|
| `20261102144011_access_fulfillment_messaging.sql` | Core tables, roles, memberships, RLS | Applied |
| `20261114120000_station_registry_drivers.sql` | Station registration and review, drivers, `my_access` | Applied |
| `20261114130000_app_sessions.sql` | Shared PHP session store for Vercel | Applied |
| `20261114155714_station_setup.sql` | Station profile, location, service areas, order rules, fees, products, hours, closures, availability | Applied |
| `20261114180000_customer_profile_addresses.sql` | Customer profile and addresses, request replay | Applied |
| `20261120000100_orders_tracking.sql` | Quotes, ordering, duplicate holds, scheduling, tracking, notifications | Applied |
| `20261120000200_operations_dispatch.sql` | Queue and priority, preparation, pickups, dispatch with SSTF, rider arrival and results, cash verification | Applied |
| `20261120000300_messages_reservations.sql` | Chat and reservation requests | Applied |
| `20261120000400_reports_jobs.sql` | Reports and scheduled jobs | Applied |
| `20261121000100_navigation_alerts.sql` | Navigation badge counts | Applied |
| `20261121000200_pickup_cash_verified.sql` | Pickup cash verified at the counter | Applied |
| `20261121000300_registry_workspace.sql` | Super Admin registry workspace | Applied |
| `20261122000100_batch_slots_capacity_cancel.sql` | 15-container trips from one barangay, 5-minute single-customer time slots, daily refill limits with per-date overrides, customer cancel window | Applied 2026-11-20 |
| `20261122000200_rider_edit_station_lifecycle.sql` | Rider editing (`get_station_riders`, `save_rider`), Super Admin suspend/remove station (`set_station_status`) | Applied 2026-11-20 |
| `20261122000300_capacity_review_fixes.sql` | Review fixes (prepared-then-cancelled stock, held repeat orders), `get_cancelled_orders` | Applied 2026-11-20 |
| `20261122000400_email_outbox_resequence.sql` | Order-update email outbox with retries (`claim_email_batch`, `finish_email`, service key only); re-planning remaining stops after a failed stop or on the rider's request (`resequence_trip`) | Applied 2026-11-20 |
| `20261122000500_checkout_rules.sql` | Station rules for the checkout "Good to know" box, returned by `get_booked_slots` | Applied 2026-11-20 |
| `20261122000600_email_outbox_fixes.sql` | Email outbox fixes: a queued email is dropped if the customer turns off order updates; no sixth try after an expired fifth | Applied 2026-11-20 |

`supabase/manual/` holds one-off data scripts (real stations, owner logins, box load units) and `2026-11-20-enable-station-jobs.sql`, which enables `pg_cron` and schedules `run_all_station_jobs()` every minute (scheduled-order release, overdue alerts, reservation expiry, location cleanup). Applied 2026-11-20; runs succeed every minute.

Order-update emails are sent by PHP (`App\Services\EmailOutbox`): one queued email after each successful form post, `php scripts/send-emails.php` on demand, or `POST /jobs/email` with header `X-Job-Key: <JOB_SECRET>` from a scheduler once `JOB_SECRET` (24+ characters) is set. `supabase/checks/` holds read-only preflight and verification queries for the station setup migration.

## Applying a migration

The developer network blocks direct Postgres connections (ports 5432/6543 never finish the handshake), so `supabase db push` times out. HTTPS works, so apply one file at a time through the Management API, then verify with read-only queries:

```powershell
npx supabase db query --remote --read-only "select count(*) from information_schema.columns where table_name = 'station_settings' and column_name = 'batch_capacity_load_units'"
npx supabase db query --remote -f supabase/migrations/<file>.sql
```

This writes to production: apply only migrations that passed the local suites, in order, and only when asked. Pasting the file into the dashboard SQL Editor is the fallback. Older files were applied through the SQL Editor, so the CLI migration history table is not a reliable record; the table above is.

## Tests

The SQL suites run every migration, unchanged, in an in-memory PostgreSQL (PGlite) with test-only `auth.users`, `auth.uid()` and roles, then switch into client roles to check RLS, grants and RPC behaviour. They never touch the hosted project.

```powershell
foreach ($t in Get-ChildItem supabase\tests\*.mjs) { node $t.FullName }
```

| Suite | Covers |
|---|---|
| `security.mjs` | Base RLS and grants |
| `registry.mjs`, `registry-workspace.mjs` | Station registration, review, Super Admin workspace |
| `station-setup.mjs` | Station setup RPCs and RLS |
| `customer.mjs` | Customer profile and addresses |
| `orders.mjs` | Pricing, ordering, duplicates, scheduling, tracking |
| `operations.mjs` | Queue, priority, dispatch, SSTF, rider flow, cash |
| `messages.mjs` | Chat and reservations |
| `reports.mjs` | Reports and jobs |
| `capacity.mjs` | Batch ceiling, 5-minute slots, daily refill limits, cancel window, settings |
| `capacity-fixes.mjs` | Review fixes and the cancelled-orders list |
| `lifecycle.mjs` | Rider editing, station suspend/remove |
| `email-resequence.mjs` | 15-container batches per barangay with the rest in the next trip, stop re-planning, email outbox retries |

PGlite runs one session, so it does not prove behaviour under truly concurrent connections; trigger backstops cover the booking races. `tests/auth-live.php` and `tests/registry-live.php` run against the hosted project (they create and delete test accounts); do not include them in routine runs.

See [database design](../docs/database-design.md) and the [implementation plan](../docs/superpowers/plans/2026-11-20-batch-capacity-station-ops.md) for the rules behind these functions.
