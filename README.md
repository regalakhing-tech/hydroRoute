# DESIGN AND DEVELOPMENT OF A WEB-BASED ORDERING AND DELIVERY MANAGEMENT SYSTEM USING AN ORDER PROCESSING ALGORITHM FOR WATER REILLING STATIONS IN HAMTIC, ANTIQUE

A Thesis Presented to the  
Faculty of the College of Computing and Information Sciences  
University of Antique – Hamtic Campus  
Guintas, Hamtic, Antique

In Partial Fulfillment for the Degree in  
Bachelor of Science in Computer Science

by

Tricia Mae P. Amado  
Veronica Sariego  
Jean Encarnacion  
Faith Nicole Gain  
Angel Mae A. Labauan

November 202

## Implemented UI

The app now includes 44 public, authentication-preview, and role pages styled with compiled Tailwind CSS. Customer, Rider, Station Admin and Super Admin each have a dedicated login page and workspace. The light-blue/cyan UI follows the approved mockups, with generated water imagery, responsive navigation, forms, filters, dialogs, local drafts and CSV exports.

This is UI only: authentication, Google OAuth and Supabase business records are not connected. Sample workspace routes are public previews. Forms clearly identify browser-local behavior and never save passwords. Super Admin pages show registration metadata only.

See [UI guide and all role links](docs/ui-implementation.md). Open `/login` to choose a role, or use `/login/customer`, `/login/rider`, `/login/station-admin` and `/login/super-admin`. The original thesis page remains at `/thesis`.

The title and date above preserve the supplied text. Update the incomplete year and spelling in `config/thesis.php` when the final wording is confirmed.

## Run locally

Requirements: PHP 8.4 or newer compatible with PHP 8, with cURL and JSON extensions. The compiled UI runs without npm or Supabase configuration. Node.js is needed only to rebuild Tailwind or run DOM tests.

From the project directory:

```powershell
Copy-Item .env.example .env
php -S 127.0.0.1:8000 -t public public/router.php
```

Open `http://localhost:8000`. The health endpoint is `http://localhost:8000/api/health`.

If `.env` already exists, edit it instead of copying over it. Add your Supabase project URL and publishable key. The local loader accepts single-line `NAME=value` entries and optional wrapping quotes. Process environment values take precedence. Multiline values, shell expansion, and inline comments are not supported.

All UI pages work before Supabase is configured. The health response reports whether configuration values are present; it does not claim that a database connection was tested.

Composer is optional for the dependency-free starter. Its metadata defines autoloading and a `composer start` command for later development.

## Main files

| Location | Responsibility |
| --- | --- |
| `api/index.php` | Vercel PHP function entry point |
| `public/index.php` | HTTP request handling and safe asset serving |
| `routes/web.php` | Explicit application routes |
| `app/Controllers/` | Page and endpoint handlers |
| `app/Core/` | Router, response handling, local environment loading |
| `app/Services/SupabaseClient.php` | HTTPS requests using the publishable key and optional user token |
| `app/Views/` | PHP templates and reserved role-specific folders |
| `config/thesis.php` | Exact thesis title, institution, authors, and date |
| `config/app.php` | Station name and deployment settings |
| `config/supabase.php` | Environment-backed Supabase configuration |
| `supabase/` | Reserved migrations, database tests, and background functions |
| `vercel.json` | Pinned PHP runtime and routing configuration |

See [file management](docs/file-management.md), [Vercel deployment](docs/deployment.md), [Supabase setup](supabase/README.md), and the [development plan](docs/superpowers/plans/2026-11-01-hamtic-water-station-php-supabase.md).

## Rebuild styles and verify

```powershell
npm ci
npm run build:css
php tests/ui-smoke.php http://127.0.0.1:8000
node tests/ui-dom.mjs http://127.0.0.1:8000
```

Run the checks while the PHP server is running. Source styles are in `resources/css/app.css`; generated CSS is stored in `public/assets/css/app.css`.

## Multiple stations

The UI represents one shared Hamtic platform with multiple station registrations and isolated station workspaces, as specified in the revised development plan. Current sample records are local fixtures. Real station isolation, authentication and database access policies remain backend implementation work.
