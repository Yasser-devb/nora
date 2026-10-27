# Changelog

All notable changes are recorded here. Follow the
[release policy](RELEASE_POLICY.md) and keep entries factual. The project is
pre-release; there are no published versions.

## Unreleased

- Ignore local key material and generated distribution archives, restrict CI
  token permissions, and validate the Nora VS Code extension in CI.
- Added application bootstrap, environment/config loading, HTTP lifecycle,
  structured error handling/logging, and request exception mapping.
- Added validation, PDO query/transaction helpers, and migration tracking.
- Added safe CLI scaffolding/generators, session/authentication integration,
  authorization, CSRF, and escaped `.nora` templates.
- Added unit, integration, and security tests plus GitHub Actions CI.
- Documented that live MySQL/MariaDB integration, production deployment, and
  optional AI integration remain unverified or planned.
- Added Moroccan project positioning, scoped comparison research, contribution
  governance, release policy, public issue/PR templates, tutorial, API
  reference, and roadmap. The framework code is now licensed under MIT; the
  copyright-holder field in `LICENSE` remains marked for maintainer
  confirmation.
- Added an opt-in server-rendered reactive UI prototype with explicit actions,
  session-owned page state, CSRF/revision checks, and a delegated browser
  runtime that reconciles updates without full-page navigation. Generated
  projects include a coordinated counter example and a Playwright browser
  integration test. Its session middleware is prepended so existing
  session-dependent middleware runs only after session startup.
- Added the Nora website as a Nora application, including an experimental
  homepage counter backed by registered PHP actions, a documented request-to-
  UI workflow, and a capability table that separates implemented, experimental,
  and planned work. Browser tests verify increment/reset without navigation.
- Prepared proposed governance guidance distinguishing community pull
  requests from maintainer-approved changes and official releases, including
  branch/tag protection recommendations, a CODEOWNERS review route, and
  trademark/branding guidance. GitHub settings were not changed.
- Prepared Composer archive metadata and development-file exclusions for
  `morave/nora`, added package installation and Packagist release guidance,
  and fixed the CLI autoloader path for Composer-installed use. A temporary
  archive install, PSR-4/bootstrap smoke test, and installed CLI check passed.
  Packagist distribution remains unavailable/unverified pending confirmation
  of the license notice's rights-holder name, package identity verification,
  and an actual release.
