# Nora Roadmap

**Status:** Direction, not a release commitment. No milestone dates are
promised. Work should be prioritized by reliability, safety, and user value,
not feature count.

## Current baseline

The repository contains a tested early framework foundation: bootstrap and
HTTP dispatch, layered configuration, error handling/logging, validation,
PDO helpers and migration tracking, CLI scaffolding, session/authentication
integration points, authorization/CSRF, and escaped plain-text templates.
The framework has unit and regression coverage, but real MySQL/MariaDB
integration is unavailable in the current environment. See the
[implementation status](audit/IMPLEMENTATION_STATUS.md) for evidence and
limitations.

The first reactive vertical slice is now implemented and browser-tested:
generated projects include an SSR counter page, one delegated framework
runtime, explicit server actions, session/CSRF/revision validation, and
in-place keyed reconciliation. Adjacent display components share one
server-owned page state. This does not complete the component hierarchy,
reactive forms, navigation, or broad browser support described in the
[reactive architecture](../architecture/REACTIVE_UI_ENGINE.md).

## Readiness work before a public contribution launch

- **License and contribution terms:** the framework code is licensed under
  MIT. Confirm the legal rights-holder name in `LICENSE`, clarify the license
  scope for the separate website example and VS Code extension, and review
  contributor terms before a stable release.
- **Trust and safety:** enable and test GitHub private vulnerability
  reporting; publish monitored security and conduct-reporting channels.
- **Repository operations:** set branch protection and required CI checks,
  confirm issue/PR workflows and maintainer responsibilities, and make the
  repository public only when its permissions and terms are clear.
- **Public distribution:** a Composer archive has been installed in a clean
  temporary project and its PSR-4 autoload, bootstrap route, and Composer CLI
  binary were smoke-tested. Packagist publication remains blocked on
  confirmation of the license notice's rights-holder name, publisher/package
  identity verification, an approved release tag, and a verified public
  Packagist install.
- **Confidence:** run the database integration suite against a real supported
  MySQL/MariaDB service in CI or an explicitly documented release check.
- **Documentation:** keep tutorial examples executable, document compatibility
  and limitations, and prepare a review process for external changes.

These are readiness requirements, not completed features. License scope and
reporting limitations must remain prominent until resolved.

## Near-term engineering priorities

1. Make database behavior reproducible against a real server; validate
   migrations, failure recovery, and supported driver/version claims.
2. Strengthen the end-to-end scaffold/install/request path so installation
   works outside the maintainer's checkout.
3. Evolve the reactive page model from shared adjacent markup toward explicit
   typed state/props and nested-component ownership, with authorization and
   security regression tests at every step.
4. Add reactive forms, server-side validation feedback, and progressive
   enhancement only after defining typed input binding and accessible error
   behavior.
5. Improve navigation/recovery semantics and measure payload, render, and DOM
   update costs before optimizing or adding transports.
6. Define and test compatibility behavior before stabilizing public contracts.
7. Obtain an independent security review before making production-readiness
   claims; address findings and publish the scope of any review.

## Stable-release direction

The project may consider `1.0.0` only after the MIT license notice has a
maintainer-confirmed rights-holder name, the license scope and contributor
policy are reviewed, core APIs are reviewed for stability, a tested
PHP/database matrix is documented, database integration is reproducible,
security and installation limitations are transparent, user-facing docs are
complete for the supported surface, and release/maintenance capacity exists. The
[release policy](../RELEASE_POLICY.md) defines intended versioning and
compatibility principles.

No schedule or maintenance window is promised yet. A stable version should
have an explicit deprecation process and support policy rather than an
assumption that every past minor release will receive fixes.

## Longer-term possibilities

- Optional AI integration as a separately optional, provider-neutral layer
  with schema-validated arguments, per-tool authorization, bounded execution,
  and redacted audit logs. It is not implemented.
- Additional language documentation in French and Arabic, maintained from
  the English source and reviewed by fluent contributors. Translations are
  not yet available.
- Broader compatibility, performance measurement, deployment guidance, or
  additional database support only where tests and maintainers can sustain
  those claims.

Ideas in this section are planned possibilities, not commitments or current
capabilities. Feature proposals should explain the user need, maintenance
cost, compatibility impact, and security consequences.
