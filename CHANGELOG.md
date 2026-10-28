# Changelog

All notable changes are recorded here. Follow the
[release policy](RELEASE_POLICY.md) and keep entries factual.

## v0.1.2 — 2026-10-28

- Add an optional password-login service backed by application-provided
  account-provider and password-account contracts. Login attempts use a
  required rate limiter, reject disabled accounts, return the same generic
  result for unknown accounts and incorrect passwords, and rehash passwords
  when the native password API requires it.
- Correct the MySQL CI service setup so repository files are no longer bind
  mounted before checkout; create the least-privilege test account after
  checkout instead.
- Make the MySQL migration integration fixture order deterministic.
- Document the authentication boundary, release limitations, and verified CI
  scope. Account persistence, registration, email verification, password
  recovery, MFA, and authentication scaffolding remain out of scope.
- Verification: `composer validate --strict`, `composer audit`,
  `composer test -- --display-warnings` (125 tests, 545 assertions, 4 skipped),
  `npm run test:browser` (2 tests), PHP syntax checks, and `git diff --check`
  passed locally. The four skipped tests include platform-dependent checks
  and database integration tests requiring a local MySQL DSN; the MySQL 8.4
  CI matrix is run separately for this release commit.

## v0.1.1

- Return HTTP 429 before downstream handlers for rate-limited requests while
  retaining rate-limit metadata headers, including `Retry-After`.
- Document lexicographical migration ordering and add deterministic migration
  fixtures and failure-history assertions.

## v0.1.0

- Provide the independent PHP framework core: application bootstrap and HTTP
  lifecycle, layered configuration/environment loading, structured exception
  handling and logging, validation, PDO helpers, migrations, CLI scaffolding,
  sessions, authentication integration points, authorization, CSRF, and
  escaped `.nora` templates.
- Add parameterized CRUD query construction, nested transaction/savepoint
  handling, migration recovery behavior, and opt-in query diagnostics.
- Add opt-in file-backed rate limiting and partial private-upload intake and
  storage APIs. The rate limiter is single-host/shared-filesystem only when
  locking semantics are verified; upload deployment permissions, real
  multipart server behavior, metadata persistence, and large-download
  streaming remain incomplete or unverified.
- Add an experimental server-rendered reactive UI with session-owned page
  state, independent nested components, explicit action authorization, CSRF
  and revision checks, and opt-in validated reactive forms. This API is
  unstable, has local Chromium coverage only, and is not a security or
  production-readiness certification.
- Add PHPUnit and browser regression coverage, Composer archive consumer
  verification, and CI configuration with an ephemeral MySQL service.
- Add framework installation guidance, API and architecture documentation,
  governance materials, and an implementation roadmap.
- Keep the public Nora Template extension information page at
  `extensions/vscode-nora/README.md`; its implementation remains a separate
  local project. Exclude the standalone website example from the public
  framework tree while preserving local project files.
- Known gaps include the planned independent security audit, real local
  MySQL verification, production deployment/ACL checks, complete account
  lifecycle and optional AI integration, and broader browser support.
