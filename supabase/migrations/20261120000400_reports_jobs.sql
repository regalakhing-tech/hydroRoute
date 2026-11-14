-- Phase 8: station-private reports and the station job (scheduled release, overdue flags, reservation expiry,
-- location retention). Requires 20261120000300_messages_reservations.sql. Paste the whole file into the SQL Editor.
-- The Supabase Cron schedule is separate: see docs/station-jobs-cron.sql (needs the pg_cron extension).
begin;

create table if not exists private.station_job_runs (
 station_id uuid not null references public.stations(id), job_type text not null, scheduled_slot timestamptz not null,
 started_at timestamptz not null default now(), finished_at timestamptz, status text not null default 'running' check (status in ('running','done','failed')),
 error_class text, released int not null default 0, expired int not null default 0, flagged int not null default 0,
 primary key (station_id, job_type, scheduled_slot));
alter table private.station_job_runs enable row level security;
revoke all on private.station_job_runs from public, anon, authenticated;

-- One station, one minute slot. Safe to call again for the same slot; never waits for Cron to decide if the station is open.
create or replace function public.run_station_jobs(p_station_id uuid) returns jsonb language plpgsql security definer set search_path = '' as $$
declare slot timestamptz := date_trunc('minute', now()); o record; n_released int := 0; n_expired int := 0; av jsonb; r record;
begin
 insert into private.station_job_runs(station_id, job_type, scheduled_slot) values (p_station_id, 'minute', slot) on conflict do nothing;
 if not found then return jsonb_build_object('station_id', p_station_id, 'skipped', true); end if;
 begin
  perform 1 from public.stations where id = p_station_id and active and registration_status = 'approved' for share;
  if found then
   av := private.availability(p_station_id, now());
   -- D4: scheduled orders join the queue once their service day has an open window now. Late releases show as overdue.
   if coalesce((av->>'is_open')::boolean, false) then
    for o in select id from public.orders where station_id = p_station_id and status = 'scheduled' and service_date <= (now() at time zone 'Asia/Manila')::date order by requested_at, id for update skip locked loop
     perform private.transition(o.id, 'confirmed', 'Released on its service day', null, 'release_scheduled');
     n_released := n_released + 1;
    end loop;
   end if;
   perform private.flag_overdue(p_station_id);
  end if;
  for r in select * from public.reservations where station_id = p_station_id and state = 'requested' and requested_at < now() for update skip locked loop
   update public.reservations set state = 'expired', decided_at = now(), version = version + 1 where id = r.id;
   perform private.notify(p_station_id, r.customer_id, 'reservation:' || r.id || ':expired', 'reservation_expired', null, 'Reservation request expired',
    'The station did not answer before ' || to_char(r.requested_at at time zone 'Asia/Manila', 'Mon DD, HH12:MI AM') || '. You can send a new request.');
   n_expired := n_expired + 1;
  end loop;
  -- Privacy: raw positions are kept 30 days; measured distance and fee decisions stay on the attempt.
  delete from public.rider_locations where station_id = p_station_id and recorded_at < now() - interval '30 days';
  update public.delivery_attempts set arrival_latitude = null, arrival_longitude = null
  where station_id = p_station_id and arrived_at < now() - interval '30 days' and arrival_latitude is not null;
  update private.station_job_runs set status = 'done', finished_at = now(), released = n_released, expired = n_expired
  where station_id = p_station_id and job_type = 'minute' and scheduled_slot = slot;
 exception when others then
  update private.station_job_runs set status = 'failed', finished_at = now(), error_class = sqlstate where station_id = p_station_id and job_type = 'minute' and scheduled_slot = slot;
 end;
 return jsonb_build_object('station_id', p_station_id, 'released', n_released, 'expired', n_expired);
end $$;

create or replace function public.run_all_station_jobs() returns jsonb language plpgsql security definer set search_path = '' as $$
declare s record; n int := 0;
begin
 for s in select id from public.stations where active and registration_status = 'approved' order by id loop
  perform public.run_station_jobs(s.id); n := n + 1;
 end loop;
 delete from private.station_job_runs where scheduled_slot < now() - interval '14 days';
 return jsonb_build_object('stations', n);
end $$;

-- Reports: one station, Manila date range [start midnight, midnight after end). Measures are kept separate:
-- completion-date order totals, collection-date cash, verification-date cash and delivery fees.
create or replace function public.get_station_report(p_station_id uuid, p_report_kind text, p_start_date date, p_end_date date, p_filters jsonb) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare a timestamptz; b timestamptz; res jsonb;
begin
 perform private.owner_check(p_station_id);
 if p_start_date is null or p_end_date is null or p_end_date < p_start_date or p_end_date - p_start_date > 366 then
  return private.envelope('{}', 'validation', jsonb_build_object('range', 'Choose a date range of up to one year.'));
 end if;
 a := p_start_date::timestamp at time zone 'Asia/Manila'; b := (p_end_date + 1)::timestamp at time zone 'Asia/Manila';
 if p_report_kind = 'sales' then
  res := jsonb_build_object('date_basis', 'Order totals by completion date; cash by collection date; verified cash by verification date.',
   'completed_orders', (select count(*) from public.orders where station_id = p_station_id and status = 'completed' and completed_at >= a and completed_at < b),
   'completed_total_centavos', (select coalesce(sum(total_centavos), 0) from public.orders where station_id = p_station_id and status = 'completed' and completed_at >= a and completed_at < b),
   'delivery_fee_centavos', (select coalesce(sum(coalesce(final_fee_centavos, quoted_fee_centavos)), 0) from public.orders where station_id = p_station_id and status = 'completed' and fulfillment = 'delivery' and completed_at >= a and completed_at < b),
   'cash_collected_centavos', (select coalesce(sum(applied_centavos), 0) from public.payments where station_id = p_station_id and collected_at >= a and collected_at < b),
   'cash_verified_centavos', (select coalesce(sum(applied_centavos), 0) from public.payments where station_id = p_station_id and state = 'verified' and verified_at >= a and verified_at < b),
   'unverified_now_centavos', (select coalesce(sum(applied_centavos), 0) from public.payments where station_id = p_station_id and state = 'unverified'),
   'daily', (select coalesce(jsonb_agg(jsonb_build_object('date', d::date,
     'completed_orders', (select count(*) from public.orders o where o.station_id = p_station_id and o.status = 'completed' and (o.completed_at at time zone 'Asia/Manila')::date = d::date),
     'completed_total_centavos', (select coalesce(sum(o.total_centavos), 0) from public.orders o where o.station_id = p_station_id and o.status = 'completed' and (o.completed_at at time zone 'Asia/Manila')::date = d::date),
     'collected_centavos', (select coalesce(sum(p.applied_centavos), 0) from public.payments p where p.station_id = p_station_id and (p.collected_at at time zone 'Asia/Manila')::date = d::date),
     'verified_centavos', (select coalesce(sum(p.applied_centavos), 0) from public.payments p where p.station_id = p_station_id and p.state = 'verified' and (p.verified_at at time zone 'Asia/Manila')::date = d::date)) order by d), '[]')
    from generate_series(p_start_date::timestamp, p_end_date::timestamp, interval '1 day') d));
 elsif p_report_kind = 'orders' then
  res := jsonb_build_object('date_basis', 'Orders by the date they were placed; status is the current status.',
   'placed', (select count(*) from public.orders where station_id = p_station_id and created_at >= a and created_at < b),
   'by_status', (select coalesce(jsonb_object_agg(status, n), '{}') from (select status, count(*) n from public.orders where station_id = p_station_id and created_at >= a and created_at < b group by status) x),
   'by_fulfillment', (select coalesce(jsonb_object_agg(fulfillment, n), '{}') from (select fulfillment, count(*) n from public.orders where station_id = p_station_id and created_at >= a and created_at < b group by fulfillment) x),
   'by_product', (select coalesce(jsonb_agg(jsonb_build_object('name', name, 'quantity', q, 'amount_centavos', amt) order by q desc, name), '[]') from (
     select i.product_name name, sum(i.quantity) q, sum(i.line_total_centavos) amt from public.order_items i join public.orders o on o.id = i.order_id
     where o.station_id = p_station_id and o.created_at >= a and o.created_at < b and o.status <> 'cancelled' group by i.product_name) x),
   'by_barangay', (select coalesce(jsonb_agg(jsonb_build_object('barangay', coalesce(brgy, 'Pickup'), 'orders', n) order by n desc), '[]') from (
     select address_barangay brgy, count(*) n from public.orders where station_id = p_station_id and created_at >= a and created_at < b group by 1) x),
   'cancel_reasons', (select coalesce(jsonb_agg(jsonb_build_object('reason', reason, 'orders', n) order by n desc), '[]') from (
     select coalesce(cancel_reason, 'No reason recorded') reason, count(*) n from public.orders where station_id = p_station_id and status = 'cancelled' and created_at >= a and created_at < b group by 1 limit 20) x),
   'daily', (select coalesce(jsonb_agg(jsonb_build_object('date', d::date,
     'placed', (select count(*) from public.orders o where o.station_id = p_station_id and (o.created_at at time zone 'Asia/Manila')::date = d::date),
     'completed', (select count(*) from public.orders o where o.station_id = p_station_id and o.status = 'completed' and (o.created_at at time zone 'Asia/Manila')::date = d::date),
     'cancelled', (select count(*) from public.orders o where o.station_id = p_station_id and o.status = 'cancelled' and (o.created_at at time zone 'Asia/Manila')::date = d::date),
     'units', (select coalesce(sum(o.total_units), 0) from public.orders o where o.station_id = p_station_id and o.status <> 'cancelled' and (o.created_at at time zone 'Asia/Manila')::date = d::date)) order by d), '[]')
    from generate_series(p_start_date::timestamp, p_end_date::timestamp, interval '1 day') d));
 elsif p_report_kind = 'deliveries' then
  res := jsonb_build_object('date_basis', 'Trips by dispatch date.',
   'trips', (select count(*) from public.delivery_trips where station_id = p_station_id and dispatched_at >= a and dispatched_at < b),
   'stops', (select count(*) from public.trip_orders s join public.delivery_trips t on t.id = s.trip_id where t.station_id = p_station_id and t.dispatched_at >= a and t.dispatched_at < b),
   'delivered', (select count(*) from public.trip_orders s join public.delivery_trips t on t.id = s.trip_id where t.station_id = p_station_id and t.dispatched_at >= a and t.dispatched_at < b and s.state = 'delivered'),
   'failed', (select count(*) from public.trip_orders s join public.delivery_trips t on t.id = s.trip_id where t.station_id = p_station_id and t.dispatched_at >= a and t.dispatched_at < b and s.state = 'failed'),
   'planned_km', (select coalesce(round(sum(planned_km), 1), 0) from public.delivery_trips where station_id = p_station_id and dispatched_at >= a and dispatched_at < b),
   'traveled_km', (select coalesce(round(sum(traveled_km), 1), 0) from public.delivery_trips where station_id = p_station_id and dispatched_at >= a and dispatched_at < b),
   'avg_delivery_minutes', (select round(avg(extract(epoch from (at.resolved_at - t.dispatched_at)) / 60)) from public.delivery_attempts at join public.trip_orders s on s.id = at.stop_id
     join public.delivery_trips t on t.id = s.trip_id where t.station_id = p_station_id and t.dispatched_at >= a and t.dispatched_at < b and at.state = 'delivered'),
   'failure_reasons', (select coalesce(jsonb_agg(jsonb_build_object('reason', reason, 'attempts', n) order by n desc), '[]') from (
     select at.failure_reason reason, count(*) n from public.delivery_attempts at join public.trip_orders s on s.id = at.stop_id join public.delivery_trips t on t.id = s.trip_id
     where t.station_id = p_station_id and t.dispatched_at >= a and t.dispatched_at < b and at.state = 'failed' group by 1 limit 20) x),
   'by_rider', (select coalesce(jsonb_agg(jsonb_build_object('rider', name, 'trips', trips, 'delivered', delivered, 'failed', failed, 'units', units, 'cash_centavos', cash) order by trips desc, name), '[]') from (
     select coalesce(d.full_name, 'Rider') name, count(distinct t.id) trips, count(s.id) filter (where s.state = 'delivered') delivered, count(s.id) filter (where s.state = 'failed') failed,
      coalesce(sum(o.total_units) filter (where s.state = 'delivered'), 0) units,
      coalesce((select sum(p.applied_centavos) from public.payments p join public.delivery_attempts x on x.id = p.attempt_id join public.trip_orders y on y.id = x.stop_id join public.delivery_trips z on z.id = y.trip_id
       where z.station_id = p_station_id and z.rider_user_id = t.rider_user_id and z.dispatched_at >= a and z.dispatched_at < b), 0) cash
     from public.delivery_trips t left join public.trip_orders s on s.trip_id = t.id left join public.orders o on o.id = s.order_id
     left join public.drivers d on d.user_id = t.rider_user_id and d.station_id = t.station_id
     where t.station_id = p_station_id and t.dispatched_at >= a and t.dispatched_at < b group by t.rider_user_id, d.full_name) x),
   'daily', (select coalesce(jsonb_agg(jsonb_build_object('date', d::date,
     'trips', (select count(*) from public.delivery_trips t where t.station_id = p_station_id and (t.dispatched_at at time zone 'Asia/Manila')::date = d::date),
     'delivered', (select count(*) from public.trip_orders s join public.delivery_trips t on t.id = s.trip_id where t.station_id = p_station_id and s.state = 'delivered' and (t.dispatched_at at time zone 'Asia/Manila')::date = d::date),
     'failed', (select count(*) from public.trip_orders s join public.delivery_trips t on t.id = s.trip_id where t.station_id = p_station_id and s.state = 'failed' and (t.dispatched_at at time zone 'Asia/Manila')::date = d::date)) order by d), '[]')
    from generate_series(p_start_date::timestamp, p_end_date::timestamp, interval '1 day') d));
 else
  return private.envelope('{}', 'validation', jsonb_build_object('kind', 'Choose sales, orders or deliveries.'));
 end if;
 return private.envelope(res || jsonb_build_object('kind', p_report_kind, 'start', p_start_date, 'end', p_end_date));
end $$;

revoke all on function public.run_station_jobs(uuid), public.run_all_station_jobs() from public, anon, authenticated;
grant execute on function public.run_station_jobs(uuid), public.run_all_station_jobs() to service_role;
revoke all on function public.get_station_report(uuid, text, date, date, jsonb) from public, anon, authenticated, service_role;
grant execute on function public.get_station_report(uuid, text, date, date, jsonb) to authenticated;
commit;
