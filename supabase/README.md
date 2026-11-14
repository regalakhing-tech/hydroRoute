# Local Supabase migration

`migrations/20261102144011_access_fulfillment_messaging.sql` is a local migration generated with the installed Supabase CLI. Nothing has been linked, pushed, started, or applied to a hosted project. It assumes the standard Supabase `auth.users`, `auth.uid()`, `anon`, and `authenticated` roles. See [database design](../docs/database-design.md) for boundaries and required backend integration.

Run the in-memory PostgreSQL-compatible security suite after installing the pinned development dependencies:

```powershell
node supabase/tests/security.mjs
```

The test creates test-only `auth.users`, `auth.uid()` and database roles, runs the actual migration unchanged, provisions fixtures as a trusted database owner, and switches into client roles to test RLS and grants. It does not start Docker or access a hosted project. This is not a hosted Supabase Auth, Data API, Realtime, or migration-deployment integration test. Before applying to a project, review for existing schema/name conflicts, run the migration and security/advisor checks in a disposable hosted or local Supabase environment, and verify Auth signup and API exposure. Never expose service credentials to clients.

## Connect later

Use one shared Supabase project for the Hamtic multi-station platform.

1. Review the migration for existing schema/name conflicts before applying it.
2. Initialize and link the intended project using the [official CLI workflow](https://supabase.com/docs/reference/cli/introduction), inspecting the installed command help first.
3. Review the pending SQL with the documented [migration push / dry-run workflow](https://supabase.com/docs/reference/cli/supabase-db-push), then apply only to the intended project. No such command was run here.
4. Configure email/password and, if required, [Google sign-in](https://supabase.com/docs/guides/auth/social-sign-in/auth-google), including allowed callback URLs.
5. Provision roles and memberships through trusted SQL/backend code. Signup creates customer access only; never promote a role from user-editable metadata.
6. Explicitly approve a station after review. New stations are pending and hidden from the public directory/catalog by default.
7. Connect PHP Auth/session handling and implement validated atomic order creation, participant-authorized conversation creation and real message delivery before treating the UI as live.

Participants can read each other's conversation receipts, while inserting/refreshing only their own. Profile/role provisioning, prices and order/status writes remain protected backend responsibilities. Do not expose service credentials in browser code.

See [messaging and fulfillment guide](../docs/messaging-fulfillment-guide.md) for the working local previews. Supabase Auth manages credentials and sessions: [user management](https://supabase.com/docs/guides/auth/managing-user-data), [row-level security](https://supabase.com/docs/guides/database/postgres/row-level-security).
Application role names in SQL are customer, station_admin, rider and super_admin. The station-admin and super-admin UI routes use hyphens; map these names in the later PHP authentication layer. The Supabase database role authenticated is distinct from these application roles.
