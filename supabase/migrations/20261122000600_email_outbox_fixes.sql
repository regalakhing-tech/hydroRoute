-- Review fixes for the email outbox (20261122000400): an email already queued is not sent if the customer turns off
-- order updates before it goes out, and a sender that dies after its fifth try cannot get a sixth.
-- Requires 20261122000500_checkout_rules.sql. Paste the whole file into the Supabase SQL Editor.
begin;

-- Replaces 20261122000400: settle opted-out and exhausted rows first, then lock up to 20 due emails.
create or replace function public.claim_email_batch(p_limit int) returns jsonb language plpgsql security definer set search_path = '' as $$
declare rows jsonb;
begin
 update private.email_outbox e set status = 'failed', locked_until = null, last_error = 'Customer turned off order updates'
 from public.profiles p where p.id = e.recipient_id and p.order_updates is false
  and e.status in ('pending','sending') and (e.locked_until is null or e.locked_until < now());
 update private.email_outbox set status = 'failed', locked_until = null, last_error = coalesce(last_error, 'Gave up after 5 attempts')
 where status in ('pending','sending') and attempts >= 5 and (locked_until is null or locked_until < now());
 with picked as (
  select id from private.email_outbox where status in ('pending','sending') and attempts < 5 and next_attempt_at <= now() and (locked_until is null or locked_until < now())
  order by next_attempt_at, created_at limit greatest(1, least(coalesce(p_limit, 5), 20)) for update skip locked),
 claimed as (
  update private.email_outbox e set status = 'sending', locked_until = now() + interval '2 minutes', attempts = e.attempts + 1
  from picked where e.id = picked.id returning e.*)
 select coalesce(jsonb_agg(jsonb_build_object('id', c.id, 'email', u.email, 'name', coalesce(nullif(p.display_name, ''), u.raw_user_meta_data->>'full_name', ''),
   'subject', c.subject, 'body', c.body, 'link', c.link, 'attempts', c.attempts, 'station', s.name) order by c.next_attempt_at), '[]'::jsonb)
 into rows from claimed c join auth.users u on u.id = c.recipient_id left join public.profiles p on p.id = c.recipient_id left join public.stations s on s.id = c.station_id;
 return jsonb_build_object('emails', rows);
end $$;

revoke all on function public.claim_email_batch(int) from public, anon, authenticated;
grant execute on function public.claim_email_batch(int) to service_role;
commit;
