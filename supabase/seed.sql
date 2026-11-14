-- The three pilot stations shown on the landing page. Safe to run more than once.
-- Hours are in Asia/Manila time; a null Sunday means closed on Sundays.
insert into public.stations (slug, name, barangay, address, phone, email, registration_status, active,
                             weekday_open, weekday_close, sunday_open, sunday_close, closed_notice)
values
  ('guintas', 'Guintas Water Station', 'Guintas', 'National Road, Brgy. Guintas, Hamtic, Antique', '+639170000110', '',
   'approved', true, '07:00', '18:00', '08:00', '16:00', null),
  ('poblacion', 'Poblacion Water Station', 'Poblacion', 'National Road, Poblacion, Hamtic, Antique', '+639170000111', '',
   'approved', true, '07:00', '17:00', null, null, null),
  ('riverside', 'Riverside Water Station', 'Riverside', 'Purok 2, Riverside, Hamtic, Antique', '+639123456789', '',
   'approved', true, '08:00', '17:00', null, null, 'Under maintenance')
on conflict (slug) do update set
  name = excluded.name, barangay = excluded.barangay, address = excluded.address, phone = excluded.phone,
  registration_status = excluded.registration_status, active = excluded.active,
  weekday_open = excluded.weekday_open, weekday_close = excluded.weekday_close,
  sunday_open = excluded.sunday_open, sunday_close = excluded.sunday_close, closed_notice = excluded.closed_notice;
