-- Replace the three sample stations with the real Hamtic stations. Run once in the Supabase SQL Editor.
-- The rows are renamed in place, so each owner login, settings row, product list and fee table stays attached:
--   poblacion@gmail.com -> Water Refilling Station (Poblacion 4)          8:00 AM - 6:00 PM daily
--   guintas@gmail.com   -> FREJCH - Water Refilling Station (Guinsang-an)  8:00 AM - 6:00 PM daily
--   riverside@gmail.com -> Altes - Water Refilling Station (Guinsang-an Banbanan) 8:00 AM - 5:00 PM daily
-- Guinsang-an is not a barangay name in the PSA list for Hamtic, so those two stations keep the text only;
-- each owner picks the official barangay and sets the map pin in Settings > Profile.
begin;

with s(id, name, slug, barangay, psgc, opens, closes) as (values
 ('67f09e55-0cf1-477e-965e-336b945a0aa3'::uuid, 'Water Refilling Station', 'water-refilling-station', 'Poblacion 4', '0600608048', '08:00'::time, '18:00'::time),
 ('5dc7159f-4969-4265-9760-b0773ebf33a0'::uuid, 'FREJCH - Water Refilling Station', 'frejch-water-refilling-station', 'Guinsang-an', null, '08:00'::time, '18:00'::time),
 ('4e94455c-149e-4122-ba88-d6f3e4995fa0'::uuid, 'Altes - Water Refilling Station', 'altes-water-refilling-station', 'Guinsang-an Banbanan', null, '08:00'::time, '17:00'::time))
update public.stations t set name = s.name, slug = s.slug, barangay = s.barangay, address = s.barangay || ', Hamtic, Antique',
 barangay_id = (select b.id from public.barangays b where b.psgc_code = s.psgc), weekday_open = s.opens, weekday_close = s.closes,
 sunday_open = s.opens, sunday_close = s.closes, closed_notice = null, profile_version = t.profile_version + 1, updated_at = now()
from s where t.id = s.id;

-- Opening hours: every day, one window.
delete from public.station_opening_hours where station_id in ('67f09e55-0cf1-477e-965e-336b945a0aa3', '5dc7159f-4969-4265-9760-b0773ebf33a0', '4e94455c-149e-4122-ba88-d6f3e4995fa0');
insert into public.station_opening_hours(station_id, weekday, window_no, opens_at, closes_at)
select s.id, d, 1, s.opens, s.closes from (values
 ('67f09e55-0cf1-477e-965e-336b945a0aa3'::uuid, '08:00'::time, '18:00'::time),
 ('5dc7159f-4969-4265-9760-b0773ebf33a0'::uuid, '08:00'::time, '18:00'::time),
 ('4e94455c-149e-4122-ba88-d6f3e4995fa0'::uuid, '08:00'::time, '17:00'::time)) s(id, opens, closes) cross join generate_series(0, 6) d;

-- The old "Riverside / Under maintenance" sample closure is lifted.
update public.station_closures set cancelled_at = now(), cancel_reason = 'Sample closure removed', version = version + 1
 where station_id = '4e94455c-149e-4122-ba88-d6f3e4995fa0' and cancelled_at is null;
update public.station_availability set manual_mode = null, begins_at = null, expires_at = null, public_notice = '', version = version + 1
 where station_id in ('67f09e55-0cf1-477e-965e-336b945a0aa3', '5dc7159f-4969-4265-9760-b0773ebf33a0', '4e94455c-149e-4122-ba88-d6f3e4995fa0');

select name, slug, barangay, address from public.stations order by name;
commit;
