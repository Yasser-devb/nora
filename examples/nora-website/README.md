# Nora Framework Website

This is an independently bootable Nora application that renders the project
website using Nora routes, its escaped template renderer, its HTTP lifecycle,
and its experimental reactive component runtime.

## Requirements

- PHP 8.2+
- Composer 2
- The PHP extensions required by the Nora root package

## Run locally on Windows

From the Nora repository root:

```powershell
cd examples\nora-website
composer install
Copy-Item .env.example .env
php -S 127.0.0.1:8000 -t public public/router.php
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000). Use `Ctrl+C` to stop the
development server. The PHP built-in server is for local development only.

`APP_BASE_URL` controls the optional canonical URL and whether the playground
session cookie is marked `Secure`. The sample `.env` targets loopback HTTP;
configure HTTPS and a correct base URL before any deployment.

## Routes

- `/` — project overview
- `/features` — capabilities and maturity
- `/docs/getting-started` and `/docs` — practical guides
- `/architecture` — actual request and component boundaries
- `/playground` — server-rendered reactive counter prototype
- `/examples`, `/roadmap`, `/changelog`, `/community`, `/contact`
- Other paths — branded 404 page

The site does not use a frontend framework or an external font/CDN dependency.
The homepage itself is a reactive Nora page: its PHP counter action and reset
control run through the framework-owned runtime without reloading the document.
The PHP preview, value, and controls appear together in the hero. The
playground uses the same server-registered action model. Both interfaces are
experimental; this website does not imply the framework is production-ready.
The homepage also explains the request/action/render workflow and labels
implemented, experimental, and planned capabilities separately.

No contact form submits data, no authentication identity is assumed, and the
license decision remains pending maintainer approval. Private GitHub
vulnerability reporting availability and repository protection settings are
not asserted as enabled.

## Tests

From the Nora repository root, install the example Composer dependency and run
the repository's browser tests:

```powershell
cd examples\nora-website
composer install
cd ..\..
npm run test:browser
```

The website browser test starts this application with PHP's local server and
verifies its routes, responsive navigation and layout, escaped branded 404,
homepage increment/reset actions without navigation, and the playground's
successful and rejected action flows. The existing generated-project browser
test runs separately.
