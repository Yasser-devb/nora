# Nora Framework v0.1.0

**Draft release notes. This release has not been approved, tagged, or
published.** Publication is gated on maintainer review and the planned
independent security review.

Nora is an independent PHP framework with an explicit core, Composer
installation metadata, a CLI, and documented HTTP, configuration, validation,
database, security, and template APIs.

## Included

- Application bootstrap, HTTP request/response handling, routing and
  middleware, configuration, dependency injection, validation, structured
  logging, and escaped `.nora` template rendering.
- CLI scaffolding and generators, session/authentication integration points,
  authorization, and CSRF middleware.
- Parameterized SELECT, INSERT, UPDATE, and DELETE query builders, nested
  transaction/savepoint handling, migrations, and opt-in query diagnostics.
- Opt-in file-backed rate limiting and partial bounded upload/private-storage
  APIs.
- An experimental server-rendered reactive UI with nested components and
  opt-in validated forms. This API is unstable and is not production certified.
- PHPUnit and Chromium browser coverage, package-consumer verification
  tooling, and framework documentation.

## Requirements and installation

- PHP 8.2 or newer and Composer 2.
- PDO with `pdo_mysql` for the supported MySQL/MariaDB baseline.

The framework's first version is proposed as `v0.1.0`. The Composer package
name is `morave/nora`; no Packagist release or public stable installation has
been verified. Follow the repository's [installation instructions](../README.md)
for source-checkout testing. Do not treat `composer require morave/nora` as
available until the package is published and a clean consumer install is
verified.

## Limitations and verification

Reactive UI, rate limiting, and upload support have documented experimental or
operational limitations. Live MySQL behavior, production deployment and
permissions, broader browser compatibility, and the independent security
review remain unverified. Automated tests are not a security certification.
See the [implementation status](audit/IMPLEMENTATION_STATUS.md) and
[roadmap](ROADMAP.md).

The separate Nora Template VS Code extension is not part of this framework
release. Its local prepared version is `0.1.2`; Marketplace publication has
not been verified. The standalone website example is also excluded from the
framework release.