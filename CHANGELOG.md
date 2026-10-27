# Changelog

All notable changes are recorded here. Follow the
[release policy](RELEASE_POLICY.md) and keep entries factual. The project is
pre-release; there are no published versions.

## Unreleased

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
  reference, and roadmap. The open-source license remains pending owner
  approval.
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
  trademark/branding guidance. Composer license metadata remains proprietary
  pending confirmation of licensing authority and owner approval; no LICENSE
  file was published and GitHub settings were not changed.
