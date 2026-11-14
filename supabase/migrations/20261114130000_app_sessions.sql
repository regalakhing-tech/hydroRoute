-- PHP sessions shared by every Vercel instance. Per-instance session files made logins vanish
-- when the next request landed on a different instance. Only the server's secret key can reach them.
-- Idempotent so it can be re-run from the SQL Editor.

create table if not exists public.app_sessions (
  id text primary key check (id ~ '^[0-9a-f]{64}$'), -- sha256 of the session cookie, never the cookie itself
  data text not null,                                -- base64 of the PHP session payload
  expires_at timestamptz not null
);
create index if not exists app_sessions_expires_at_idx on public.app_sessions (expires_at);
alter table public.app_sessions enable row level security;
revoke all on public.app_sessions from public, anon, authenticated;

create or replace function public.session_read(p_id text) returns text
language sql stable security definer set search_path = '' as $$
  select data from public.app_sessions where id = p_id and expires_at > now();
$$;

-- Empty data deletes the session. Expired rows are cleared on every write.
create or replace function public.session_write(p_id text, p_data text, p_ttl integer)
returns void language plpgsql security definer set search_path = '' as $$
begin
  if coalesce(p_data, '') = '' then
    delete from public.app_sessions where id = p_id;
  else
    insert into public.app_sessions (id, data, expires_at)
    values (p_id, p_data, now() + make_interval(secs => greatest(p_ttl, 60)))
    on conflict (id) do update set data = excluded.data, expires_at = excluded.expires_at;
  end if;
  delete from public.app_sessions where expires_at < now();
end $$;

revoke all on function public.session_read(text), public.session_write(text, text, integer) from public, anon, authenticated;
grant execute on function public.session_read(text), public.session_write(text, text, integer) to service_role;
