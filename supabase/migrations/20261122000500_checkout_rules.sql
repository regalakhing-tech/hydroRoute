-- Checkout "Good to know" box: the station's customer-facing rules (cancel window, trip size, daily refill limit,
-- booking days) returned with the booked slots, because customers cannot read station_settings directly.
-- Requires 20261122000400_email_outbox_resequence.sql. Paste the whole file into the Supabase SQL Editor.
begin;

-- Replaces 20261122000100: adds 'rules'. Slots stay times only, with no customer details.
create or replace function public.get_booked_slots(p_station_id uuid) returns jsonb language plpgsql stable security definer set search_path = '' as $$
declare today date := (now() at time zone 'Asia/Manila')::date; cfg public.station_settings; days int;
begin
 perform private.customer_guard();
 select * into cfg from public.station_settings where station_id = p_station_id;
 days := coalesce(cfg.booking_days, 7);
 return private.envelope(jsonb_build_object(
  'slots', coalesce((select jsonb_agg(distinct to_char(private.slot_start(o.requested_at) at time zone 'Asia/Manila', 'YYYY-MM-DD"T"HH24:MI'))
   from public.orders o where o.station_id = p_station_id and o.schedule_mode = 'scheduled' and o.status not in ('cancelled','on_hold')
    and o.customer_id <> auth.uid() and o.requested_at >= now() - interval '5 minutes' and o.service_date <= today + days), '[]'::jsonb),
  'refills_left', coalesce((select jsonb_object_agg(x.d, greatest(x.lim - private.daily_refills_booked(p_station_id, x.d, null), 0))
   from (select g::date d, private.daily_refill_limit(p_station_id, g::date) lim from generate_series(today::timestamp, (today + days)::timestamp, interval '1 day') g) x
   where x.lim is not null), '{}'::jsonb),
  'rules', jsonb_build_object('cancel_minutes', coalesce(cfg.customer_cancel_minutes, 15), 'trip_containers', coalesce(cfg.batch_capacity_load_units, 15),
   'daily_refills', cfg.daily_gallon_limit, 'booking_days', days, 'max_items', cfg.max_order_units)));
end $$;

revoke all on function public.get_booked_slots(uuid) from public, anon, authenticated, service_role;
grant execute on function public.get_booked_slots(uuid) to authenticated;
commit;
