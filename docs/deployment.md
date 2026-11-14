# PHP deployment on Vercel

## Runtime

This starter pins `vercel-php@0.9.0`, a community-maintained PHP 8.5 runtime. It is not an official Vercel PHP runtime. Verify a preview deployment before production use. See the [runtime project](https://github.com/vercel-community/php) and [Vercel runtime documentation](https://vercel.com/docs/functions/runtimes).

The repository root is the deployment root. `vercel.json` directs requests to `api/index.php`, which loads the PHP application. The application serves only its two registered public assets and its explicit routes. No general filesystem route exposes configuration or thesis files.

## Setup

1. Place this project in a Git repository you control and import it into Vercel, or use the Vercel CLI from the project directory.
2. Choose Other as the framework and keep the project root at this directory. Do not select `public/` as the Vercel root, and do not add a separate static output directory.
3. Use Node.js 22.x for the community runtime builder, following its current documentation. The application itself remains PHP.
4. Set the following environment variables in the intended Preview or Production environment:

| Variable | Value |
| --- | --- |
| `APP_NAME` | The station's public name |
| `APP_ENV` | `production` |
| `APP_URL` | The deployed HTTPS origin |
| `APP_TIMEZONE` | `Asia/Manila` |
| `SUPABASE_URL` | The station's Supabase HTTPS project origin |
| `SUPABASE_PUBLISHABLE_KEY` | That project's `sb_publishable_...` key |
| `SUPABASE_SECRET_KEY` | Server-only `sb_secret_...` key; creates customer accounts after the email code is verified |
| `APP_PREVIEW` | `false`. `true` shows sample data without logins, for demos and automated page tests only |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION` | SMTP server for PHPMailer, for example `smtp.gmail.com`, `587`, `tls` |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | SMTP login; for Gmail, a Google App Password |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Sender shown on verification emails |

5. Deploy a preview and check the title page, `/api/health`, CSS, and JavaScript. A health result with `supabase_configured: true` indicates supplied configuration, not verified connectivity.
6. Verify that `/.env`, `/config/thesis.php`, `/bootstrap.php`, and `/README.md` return 404.
7. Test a real authorized database operation only after the corresponding migration, grants, RLS, and user authentication are implemented.

The `.vercelignore` file excludes environment files, thesis sources, documentation, tests, local scratch files, and migrations from deployment uploads. The runtime's Composer support can install future dependencies during its build. Do not put credentials in Git or PHP templates.

## Sessions and scheduled work

Local development uses native PHP file sessions. On Vercel (`VERCEL` is set), sessions are stored in Supabase `public.app_sessions` through `App\Services\SessionStore`, because separate serverless instances do not share files. Run `supabase/migrations/20261114130000_app_sessions.sql` in the Supabase SQL Editor before deploying; without it every login fails with "Your session expired". Rows are keyed by a SHA-256 of the HttpOnly session cookie and are reachable only with the secret key.

Functions run in `hnd1` (Tokyo), next to the Supabase project (`ap-northeast-1`). Change `regions` in `vercel.json` if the database moves.

## Live ordering migrations

Run the migrations in the Supabase SQL Editor in file-name order. Each file is one transaction, and the later files depend on the earlier ones:

1. `20261102144011_access_fulfillment_messaging.sql`, `20261114120000_station_registry_drivers.sql`, `20261114130000_app_sessions.sql` (already applied).
2. `20261114155714_station_setup.sql` (Phase 2) and `20261114180000_customer_profile_addresses.sql` (Phase 3).
3. `20261120000100_orders_tracking.sql`: quotes, ordering, tracking and notifications.
4. `20261120000200_operations_dispatch.sql`: queues, dispatch, SSTF, rider results, cash and reviews.
5. `20261120000300_messages_reservations.sql`: chat and reservations. After this one, clients can no longer insert messages directly.
6. `20261120000400_reports_jobs.sql`: reports and the station job.

Do not deploy PHP that calls these RPCs before its migration is applied. Check each one with read-only queries, for example `npx supabase db query --remote --read-only "select proname from pg_proc where proname = 'submit_order'"`.

## Station jobs

Scheduled-order release, overdue alerts, reservation expiry and the 30-day rider-location cleanup run in the database through `public.run_all_station_jobs()`. Enable the `pg_cron` extension, then run `docs/station-jobs-cron.sql` once in the SQL Editor to schedule it every minute. Priority scores are calculated when a queue is read, so there is no separate refresh job. Do not depend on a continuously running PHP process or a station browser. See [Supabase Cron](https://supabase.com/docs/guides/cron).
