-- Bottled-water boxes had no load size, so the database (rule D7) allowed them for pickup only and checkout
-- refused delivery: "Some products, such as bottled water boxes, are available for pickup only at this station."
-- A box (20 x 1 L, 35 x 500 ml or 40 x 350 ml) weighs about the same as one 18.9 L container, so each box
-- counts as 1 load unit of rider capacity. Owners can change this per product in Products.
begin;
update public.products set load_units = 1, version = version + 1
 where load_units is null and image_key = 'water-bottle' and pack_text like '1 box%';
select s.name station, p.name, p.load_units from public.products p join public.stations s on s.id = p.station_id
 where p.image_key = 'water-bottle' order by s.name, p.name;
commit;
