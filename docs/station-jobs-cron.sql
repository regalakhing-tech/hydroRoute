-- HydroRoute station jobs: run once in the Supabase SQL Editor, after the Phase 8 migration
-- (20261120000400_reports_jobs.sql) and after enabling the pg_cron extension
-- (Dashboard > Database > Extensions > pg_cron).
--
-- Every minute, for each approved station, the job:
--   * releases scheduled orders on their service day once the station is open,
--   * raises overdue alerts (orders waiting longer than the station's overdue limit),
--   * expires reservation requests whose time passed without an answer,
--   * deletes rider positions older than 30 days.
-- Priority scores are calculated live whenever a queue is read, so no separate five-minute job is needed.
-- Running it twice in the same minute does nothing the second time.

select cron.schedule('hydroroute-station-jobs', '* * * * *', $$select public.run_all_station_jobs()$$);

-- Check recent runs:
--   select * from cron.job_run_details order by start_time desc limit 10;
-- Stop the schedule:
--   select cron.unschedule('hydroroute-station-jobs');
