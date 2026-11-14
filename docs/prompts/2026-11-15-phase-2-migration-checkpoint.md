# Phase 2 migration checkpoint

Prepared 15 November 2026 for the linked Supabase project `ftcgurrxmvznwxwbnrrx`.

The current requested scope ends after Phase 3. Station setup is locally implemented and reviewed. Hosted installation is the next dependency for customer profile, address and catalogue implementation.

## Run the migration

Section 6 of the supplied implementation prompt requires the user to apply each migration through Supabase SQL Editor and confirm the result.

1. Open SQL Editor for the linked project.
2. Paste the entire [station setup migration](../../supabase/migrations/20261114155714_station_setup.sql), including its transaction and installation guard.
3. Run the script and report success or the exact SQL error.

The script initializes the 47 official Hamtic barangays, station configuration, seven catalogue defaults, immutable fee versions, availability, scoped authorization and the checked RPCs. Existing catalogue prices and historical order item prices are preserved. An installation marker permits identical reruns and rejects changed SQL under the same migration identifier. Its checksum normalizes Windows and Unix line endings.

The three earlier migrations remain the prerequisite schema.

## Hosted preflight

The agent executed the [read-only preflight](../../supabase/checks/20261114155714_station_setup_preflight.sql) using the installed CLI's `--remote --read-only` mode.

- Three stations are approved.
- Shared sessions and the existing access/session RPCs are present.
- Legacy schedules passed the conversion checks.
- Default catalogue names have no duplicate matches.
- Two accounts have a global station-owner role and zero station memberships. They require explicit station assignments before owner access can work. The migration leaves their assignments for trusted provisioning.
- The Phase 2 settings table was absent at the preflight checkpoint.

## Verification after confirmation

The agent will execute the [read-only verification queries](../../supabase/checks/20261114155714_station_setup_verify.sql), inspect the installed marker, tables, RLS, policies, execution grants and seeded configuration, then verify the public availability endpoint.

The installed CLI supports this command.

```powershell
.\node_modules\.bin\supabase.cmd db query --remote --read-only --file supabase\checks\20261114155714_station_setup_verify.sql
```

Deployment depends on the confirmed migration and hosted verification. The branch is `feat/live-station-workflows`.

## Local verification

| Check | Result |
| --- | --- |
| Station setup PostgreSQL and real-role RLS | 155 assertions passed |
| Existing security PostgreSQL and RLS | 107 assertions passed |
| Registry PostgreSQL and RLS | 37 assertions passed |
| Station setup PHP | 151 assertions passed |
| Registry PHP | 63 assertions passed |
| Setup integration, including owner selection | 15 assertions passed |
| Auth, shared sessions and catalogue PHP | 49, 7 and 16 assertions passed |
| Focused setup DOM | 36 grouped checks passed |
| Existing preview DOM | 47 pages and 281 assertions passed |
| Preview HTTP and private-file boundaries | 47 pages and 61 links/assets passed |
| Existing messaging DOM and persistence regressions | 28 and 6 assertions passed |
| Existing fulfillment and product pricing | Passed |
| Legacy libcurl compatibility | Passed with `php -n` |
| PHP syntax | 119 files passed |
| Tailwind build and whitespace checks | Passed |

Independent review covered the SQL and PHP/interface implementation. Corrected findings included authorization before driver account creation, form values after redirects, closure retry payloads, preservation of saved rest days during driver provisioning, exact-cutoff behavior, coordinate-pair constraints and explicit owner workspace selection.

Screenshot QA is pending because browser automation stopped when it could not verify Chrome's current URL. Independent-session concurrency and hosted Auth/PostgREST acceptance remain pending. Local PostgreSQL checks used PGlite with actual database roles and RLS.

## Phase 3 continuation

After the Phase 2 checkpoint, implementation proceeds through customer profile and notification preferences, password change and global logout, owned addresses and defaults with optional map pins, live approved-station catalogues and delivery quotes, real customer overview and navigation counts. Phase 3 will have its own migration, tests and SQL Editor checkpoint.
