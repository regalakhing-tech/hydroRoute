# Project file management

The project separates public entry points, application code, configuration, database work, and documentation. Empty module directories contain `.gitkeep` so they can be retained in version control.

```text
api/
  index.php                         Vercel function
app/
  Controllers/
    HomeController.php              Thesis title page
    SystemController.php            Starter health response
  Core/
    Environment.php                 Local environment loading
    Response.php                    Escaped views and HTTP responses
    Router.php                      Explicit GET and HEAD routes
  Middleware/                       Reserved authentication and role checks
  Repositories/                     Reserved data-access classes
  Services/
    SupabaseClient.php              Supabase REST and RPC transport
  Views/
    home.php                        Thesis title page template
    auth/                           Reserved account templates
    customer/                       Reserved customer templates
    admin/                          Reserved staff templates
    rider/                          Reserved delivery templates
    layouts/                        Reserved shared layouts
config/
  app.php                           Station and environment settings
  supabase.php                      Project URL and publishable key
  thesis.php                        Title page metadata
public/
  index.php                         HTTP request entry point
  router.php                        Local PHP development server router
  assets/
    css/app.css                     Shared title page styles
    js/app.js                       JavaScript entry file
routes/
  web.php                           Route registration
supabase/
  README.md                         Database setup instructions
  migrations/                       Reserved schema and policy migrations
  tests/                            Reserved database security tests
  functions/                        Reserved hosted background functions
tests/
  Unit/                             Reserved business rule tests
  Integration/                      Reserved service and database tests
  Acceptance/                       Reserved user workflow scenarios
storage/                            Local-only scratch work
docs/
  file-management.md                This guide
  deployment.md                     Vercel deployment instructions
  superpowers/plans/                Reviewed system roadmap
bootstrap.php                       Configuration and class loading
composer.json                       PHP requirements and autoload metadata
vercel.json                         Serverless runtime configuration
.env.example                        Safe variable names and local defaults
.gitignore                          Version control exclusions
.vercelignore                       Deployment exclusions
README.md                           Project title and local setup
```

## Adding a module

1. Put request handlers in a controller and add the exact supported route in `routes/web.php`.
2. Keep validation and workflow orchestration in a service, with database requests in a repository.
3. Place HTML in the appropriate role folder and escape user-controlled text.
4. Add authenticated routes only after identity checks and database permissions are implemented.
5. Add schema, grants, RLS, and workflow functions through reviewed migrations.
6. Add public assets to the explicit asset map in `public/index.php`.

Build the modules in this order: accounts and permissions, customer ordering, priority queue, dispatch and rider delivery, tracking and notifications, then reports. The corresponding folders are reserved; their business features are not yet implemented.

## Files that stay private

Keep `.env`, database credentials, thesis source documents, tests, and migrations outside public routing. Do not upload customer documents or save production sessions to `storage/`. Vercel instances do not supply durable local application state. Store future application records and sessions in an appropriate durable service, and future uploaded objects in Supabase Storage.
