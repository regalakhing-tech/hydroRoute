-- Turns on the every-minute station job on the hosted project (scheduled-order release on the service day, overdue
-- alerts, reservation expiry, 30-day rider-location cleanup). Same as docs/station-jobs-cron.sql, but safe to rerun.
-- Needs the 20261120000400_reports_jobs.sql migration. Run once in the SQL Editor or with
--   npx supabase db query --remote -f supabase/manual/2026-11-20-enable-station-jobs.sql
create extension if not exists pg_cron;
select cron.schedule('hydroroute-station-jobs', '* * * * *', $$select public.run_all_station_jobs()$$);
-- Check: select jobname, schedule, active from cron.job;  Recent runs: select * from cron.job_run_details order by start_time desc limit 5;
