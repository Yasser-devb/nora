# Nora UI Integration Demo

This runnable application exercises the documented Nora UI composition,
component, form, table, and reactive action APIs together. It is for local
development and is not a production deployment template.

The page demonstrates:

- A shared layout with named navigation, header, body, and footer slots.
- A directory composed from cards, badges, search, filters, and a keyed
  sortable/paginated table.
- A create/edit modal with field-level server validation, an explicit close
  action, and a server-backed dismissible toast.
- A preferences form using checkbox, switch, radio, select, and textarea
  primitives, plus a validated profile form.
- A counter action that is allowed by its policy and a separate action that
  is deliberately denied.
- Alerts, avatars, tooltips, tabs, dropdowns, accordion, progress, separators,
  skeletons, loading feedback, breadcrumbs, statistics, and empty states.

Records and preferences live only in the reactive PHP session. There is no
database, authentication provider, or durable persistence in this sample.

## Run locally

From the Nora repository root, install the root Composer dependencies if needed,
then start PHP's built-in development server:

```powershell
composer install
php -S 127.0.0.1:8001 -t examples\nora-ui-demo\public examples\nora-ui-demo\public\router.php
```

Open [http://127.0.0.1:8001](http://127.0.0.1:8001). Use Ctrl+C to stop the
server. The app uses the root `vendor/autoload.php`; no additional packages are
required.

The form submits through the Nora reactive endpoint. Invalid submissions are
validated on the server and announced with field-level accessible errors. The
page also demonstrates an authorized counter action and a deliberately denied
action. The demo's authorization state is a fixed server-side example, not an
authentication system; real applications must derive authorization from their
trusted identity and policy layers.

For browser validation, install the repository's Node development dependencies
and Chromium once, then run from the repository root:

```powershell
npm ci
npx playwright install chromium
npm run test:browser
```

The Chromium test covers the actual demo flows, keyboard interactions, focus
restoration, table operations, server validation, denied/stale requests, and
small/large viewport overflow. It does not run an automated accessibility
scanner or certify assistive-technology behavior.

The UI API is experimental. Do not treat this sample as production
certification, an independent security audit, or a substitute for application
specific authentication, authorization, persistence, monitoring, and
deployment hardening.
