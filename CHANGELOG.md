# Changelog

All notable changes are recorded here. Follow the
[release policy](RELEASE_POLICY.md) and keep entries factual.

## v0.1.1 — 2026-10-28

- Fix rate limiting so rejected requests receive HTTP 429 before the
  downstream handler runs; preserve rate-limit response headers.
- Add regression coverage proving rejected requests do not invoke the handler.
- Make the migration-ordering integration fixture deterministic and align its
  expected status order with lexicographical migration filename ordering.
- Add unit coverage for migration execution and status ordering, and document
  the ordering contract.
- Verification: `composer validate --strict`, `composer audit --locked`,
  `npm audit`, and the PHP suite completed without failures. The full suite
  reported 121 tests, 532 assertions, and 4 skipped tests; reactive lifecycle
  tests reported 12 tests and 127 assertions. The MySQL integration tests
  were skipped because no dedicated test database was configured. The
  filesystem-permission and symlink tests were skipped because Windows did
  not enforce directory permissions or permit symlink creation. These checks
  remain unverified; see the release notes.

## Proposed v0.1.0 — unpublished; pending maintainer review

This is a release-candidate summary, not a published release. Publication is
gated on maintainer review and the planned independent security review.

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
  verification, and CI configuration. Local MySQL integration is deferred;
  CI configuration does not establish a successful hosted run.
- Add framework installation guidance, API and architecture documentation,
  governance materials, and an implementation roadmap.
- Keep the public Nora Template extension information page at
  `extensions/vscode-nora/README.md`; its implementation remains a separate
  local project. Exclude the standalone website example from the public
  framework tree while preserving local project files.
- Known gaps include the planned independent security audit, real local
  MySQL verification, production deployment/ACL checks, complete account
  lifecycle and optional AI integration, and broader browser support.

The proposed version is `v0.1.0`, selected because no conflicting framework
tag or GitHub Release exists. It has not been approved, tagged, or published.
