# Nora Framework Implementation Roadmap

**Status:** Proposed engineering sequence; no delivery dates or release
commitments. This document turns the high-level
[roadmap](ROADMAP.md) into independently reviewable work items. It does not
describe planned APIs as implemented.

## Planning basis and status definitions

This audit is based on the source, tests, Composer metadata, CI workflow, and
architecture documentation in the repository as of 2026-10-27. Status labels
mean:

- **Implemented and tested:** the stated behavior exists and has relevant
  passing tests for the specified scope.
- **Partially implemented:** some useful behavior exists, but important
  capability or coverage is absent.
- **Implemented but insufficiently tested:** code exists, but integration,
  failure, compatibility, or abuse-case evidence is not adequate.
- **Missing:** no corresponding framework capability was found in the
  inspected source/tests.
- **Blocked by a decision/dependency:** implementation should wait for a
  named policy, compatibility, external service, or prerequisite decision.

These are feature-specific classifications, not security certifications.
Existing local tests do not establish hosted CI success or production
readiness.

### Summary and dependency index

| Priority | Work item | Current status | Dependencies | Size |
|---|---|---|---|---|
| P0 | [NORA-BASE-01: Verify database behavior against a real server](#nora-base-01--verify-database-behavior-against-a-real-server) | Implemented but insufficiently tested | None | M |
| P0 | [NORA-BASE-02: Automate clean package-consumer verification](#nora-base-02--automate-clean-package-consumer-verification) | Implemented but insufficiently tested | None | M |
| P0 | [NORA-SEC-01: Close request and rendering security-boundary gaps](#nora-sec-01--close-request-and-rendering-security-boundary-gaps) | Partially implemented | None | M/L |
| P1 | [NORA-OPS-01: Provide a disposable Docker development database](#nora-ops-01--provide-a-disposable-docker-development-database) | Missing | BASE-01 | M |
| P1 | [NORA-SEC-02: Add a storage-backed rate limiter](#nora-sec-02--add-a-storage-backed-rate-limiter) | Implemented; local tests pass | SEC-01 | M/L |
| P1 | [NORA-UPL-01: Add bounded upload intake and storage adapters](#nora-upl-01--add-bounded-upload-intake-and-storage-adapters) | Partially implemented; Request integration, batch cleanup, and authorization gate unit-tested; live multipart/deployment/streaming checks pending | SEC-01; storage decision | L |
| P1 | [NORA-DB-01: Add safe CRUD query construction](#nora-db-01--add-safe-crud-query-construction) | Implemented; unit tests pass; live DB effects unverified | BASE-01 | M |
| P1 | [NORA-DB-02: Specify transaction and migration recovery semantics](#nora-db-02--specify-transaction-and-migration-recovery-semantics) | Implemented; unit coverage; live DB unverified | BASE-01 | M |
| P2 | [NORA-DB-03: Add opt-in query diagnostics](#nora-db-03--add-opt-in-query-diagnostics) | Implemented; unit tests pass | DB-01; privacy policy | S/M |
| P1 | [NORA-REACT-01: Define independent nested reactive components](#nora-react-01--define-independent-nested-reactive-components) | Implemented; focused unit/integration and local Chromium tests pass | SEC-01; existing reactive slice | L |
| P1 | [NORA-REACT-02: Add server-authoritative reactive forms](#nora-react-02--add-server-authoritative-reactive-forms) | Implemented and locally browser-tested; native POST fallback remains application-owned | REACT-01; existing Validator | M/L |
| P1 | [NORA-AUTH-01: Define and implement an account-provider boundary](#nora-auth-01--define-and-implement-an-account-provider-boundary) | Partially implemented | BASE-01; SEC-02 | L |
| P1 | [NORA-AUTH-02: Add verification and account recovery flows](#nora-auth-02--add-verification-and-account-recovery-flows) | Missing | AUTH-01; mail and token-storage decisions | L |
| P2 | [NORA-AUTH-03: Add optional two-factor authentication](#nora-auth-03--add-optional-two-factor-authentication) | Missing | AUTH-01; recovery-flow decision | L |
| P2 | [NORA-AUTH-04: Offer optional Nora Auth scaffolding](#nora-auth-04--offer-optional-nora-auth-scaffolding) | Blocked by a decision/dependency | AUTH-01; AUTH-02; package-boundary decision | M/L |
| P2 | [NORA-ORM-01: Evaluate relationships and a minimal ORM](#nora-orm-01--evaluate-relationships-and-a-minimal-orm) | Missing | DB-01; DB-02; DB-03 | XL |
| P2 | [NORA-DOC-01: Establish French and Arabic documentation workflow](#nora-doc-01--establish-french-and-arabic-documentation-workflow) | Missing | Stable English source docs; reviewer availability | M |
| P2 | [NORA-DEVX-01: Prepare Nora Template extension distribution](#nora-devx-01--prepare-nora-template-extension-distribution) | Partially implemented | Extension license and publisher decisions | S/M |

Priority is relative to reliability, security, and user impact: P0 is a
release-confidence blocker, not a declaration that all other work is unsafe.
Sizes are relative estimates, not calendar estimates: XS (hours), S (small),
M (several reviewable changes), L (multiple coordinated changes), XL
(substantial design and migration uncertainty). Effort uncertainty is called
out per item.

## Phase 0 — Foundation and confidence

### NORA-BASE-01 — Verify database behavior against a real server

- **Problem / current status:** Applications need database behavior verified
  on the advertised driver. `PdoConnection` uses prepared statements and
  supports fetches, raw execution, and callback transactions. `QueryBuilder`
  only builds SELECT. `MigrationRunner` applies and reports pending migrations;
  migration unit tests use `RecordingPDO`. `tests/Integration/DatabaseServerTest.php`
  currently checks parameter binding with SELECT and rollback of a read-only
  transaction. `.github/workflows/ci.yml` is tracked and configures MySQL 8.4,
  but hosted run results are not established by this code audit.
- **Scope / out of scope:** Expand the disposable-server integration suite to
  cover create/drop fixture tables, committed and rolled-back writes,
  migration status, repeated migration runs, migration failure/history
  behavior, and cleanup. First verify MySQL 8.4 CI execution; separately
  decide whether MariaDB is supported and add a versioned CI service if so.
  Do not point tests at developer or production databases; do not claim
  portability to untested PDO drivers.
- **Dependencies / API:** None. No public API change is required for this
  test and evidence milestone.
- **Security / compatibility:** Use an isolated test schema and least-privilege
  credentials; tests must not drop arbitrary caller-supplied databases.
  Keep the declared driver support aligned with observed behavior.
- **Tests / done when:** CI runs the live database tests without skips on the
  selected service; committed and rollback effects are asserted using
  disposable rows/tables; migration failure leaves a documented and asserted
  state; repeated runs are idempotent; cleanup runs on failure.
- **Priority / impact / effort:** P0; high reliability and release-claim
  impact; M. Uncertainty: MySQL DDL auto-commit and failure recovery differ
  from transactional drivers.
- **Deliverables:** Extend `tests/Integration/DatabaseServerTest.php`,
  add focused migration integration tests, update `.github/workflows/ci.yml`,
  and document the verified DB matrix in `architecture/DATABASE_ARCHITECTURE.md`.

### NORA-BASE-02 — Automate clean package-consumer verification

- **Problem / current status:** A Composer archive was manually installed in a
  clean temporary consumer, including an autoload/bootstrap/CLI smoke test.
  The root Composer package excludes tests/examples and development tools;
  the clean-install check is not yet a repeatable CI gate.
- **Scope / out of scope:** Add a non-publishing CI job that builds the
  package archive, inspects required and excluded paths, installs the archive
  into a temporary consumer, exercises PSR-4 autoloading, a route registered
  with `ApplicationBootstrap`, and the `vendor/bin/nora` command. Verify
  required runtime assets such as `resources/reactive/runtime.js` are present.
  Do not use Packagist as a dependency for this test and do not publish from
  CI.
- **Dependencies / API:** None; use the existing Composer package and public
  API. No new runtime dependency is intended.
- **Security / compatibility:** Use only temporary local artifacts; make no
  secrets available to the job. Keep archive exclusions from accidentally
  omitting runtime assets or including local `.env` files.
- **Tests / done when:** On supported PHP, the archive excludes tests,
  examples, editor packages, lockfiles, caches, and local environment files;
  retains `LICENSE`, `composer.json`, runtime source/assets and docs; Composer
  installs it into a clean project; route response and CLI exit status are
  asserted.
- **Priority / impact / effort:** P0; high install-trust impact; M.
  Uncertainty: archive naming and binary proxy behavior vary by Composer/OS.
- **Deliverables:** A script or workflow step, a temporary consumer fixture,
  and an update to `docs/PACKAGE_RELEASE.md` with the automated proof boundary.

### NORA-SEC-01 — Close request and rendering security-boundary gaps

- **Problem / current status:** CSRF, same-origin checks, authorization,
  bounded reactive state/output, and strict reactive action schemas exist.
  `Request::fromGlobals()` reads all of `php://input` before the reactive
  handler's 16 KiB check, so that check alone does not bound ingress memory.
  Reactive components render trusted PHP-produced HTML; the browser parses
  that HTML and does not sanitize unsafe application markup.
- **Scope / out of scope:** Document and test an explicit ingress limit at the
  web-server/reverse-proxy boundary and decide whether the framework should
  add a bounded body reader or configurable request limit. Add regression
  tests for over-limit input, malformed content lengths, CSRF/origin failures,
  authorization denial, safe generic errors, and escaping expectations.
  Obtain an independent, scoped security review before production-readiness
  claims. Do not introduce a sanitizer claim or silently rewrite trusted HTML.
- **Dependencies / API:** None for boundary documentation and test cases.
  Any bounded-reader public API needs a compatibility review.
- **Security / compatibility:** Limits must be enforced before buffering
  untrusted bodies; proxy trust must be explicit. Preserve existing
  authorization deny-by-default and CSRF behavior. Clearly label trusted
  render callbacks and require context-appropriate escaping.
- **Tests / done when:** Oversized bodies are rejected at the earliest
  controlled boundary in a testable deployment setup; application-level
  tests prove forged and cross-origin requests do not invoke transitions;
  security review scope/findings are recorded and all release-blocking
  findings have an owner-approved disposition.
- **Priority / impact / effort:** P0 for production-readiness claims; high
  risk-reduction impact; M for engineering boundaries, L/XL for independent
  review. Review effort is externally variable.
- **Deliverables:** Request-boundary tests, deployment/security documentation,
  and a dated independent review summary with limitations and remediations.

### NORA-OPS-01 — Provide a disposable Docker development database

- **Problem / current status:** CI configures MySQL 8.4, but the repository has
  no Docker Compose development environment; local database testing depends
  on manual provisioning.
- **Scope / out of scope:** Add an opt-in Compose service for a disposable,
  non-production database and a documented command sequence to run the
  integration suite. Reuse the selected CI database version and test schema.
  Do not add a production deployment stack, persistent credentials, or
  automatic migration of a user's database.
- **Dependencies / API:** Depends on BASE-01 selecting and passing a database
  service/version.
- **Security / compatibility:** Use example-only local credentials, bind to
  loopback where practical, avoid committing `.env`, and distinguish the
  development service from production configuration.
- **Tests / done when:** A clean Docker Compose start reaches health, the
  documented test command passes against the disposable service, teardown is
  documented, and the fixture cannot target a non-test DSN.
- **Priority / impact / effort:** P1; medium reliability and contributor
  onboarding impact; M. Uncertainty: Docker availability and host networking.
- **Deliverables:** `compose.yaml` (or equivalent), safe environment template,
  DB test instructions, and CI/local version alignment.

## Phase 1 — Core reliability and security primitives

### NORA-SEC-02 — Add a storage-backed rate limiter

- **Problem / current status:** Implemented as an opt-in fixed-window limiter
  with a file-backed store and middleware. It is not automatically registered.
- **Scope / out of scope:** `RateLimitStoreInterface` defines atomic bucket
  updates and cleanup; `FileRateLimitStore` uses hashed bucket filenames,
  exclusive file locking, and atomic replacement. No PDO, Redis, distributed,
  or automatic route registration is provided. This feature does not stop
  volumetric denial of service.
- **Dependencies / API:** Uses the existing SEC-01 request/middleware
  boundaries. Implemented API:
  ```php
  $store = new FileRateLimitStore($storageDirectory);
  $limiter = new RateLimiter($store, limit: 10, windowSeconds: 60);
  $middleware = new RateLimitMiddleware($limiter, $keyResolver);
  ```
- **Security / compatibility:** Keys are restricted to 128 safe ASCII
  characters and SHA-256 hashed before storage. The file adapter serializes
  access with a directory-wide lock and is intended for a single host or a
  shared filesystem only when locking semantics are verified. Middleware does
  not derive keys from IP or forwarded headers and returns 503 on storage
  failure; there is no fail-open mode.
- **Tests / done when:** Window boundaries, concurrent increments, expiry,
  key spoofing/proxy handling, backend outage policy, 429 response and
  `Retry-After` have unit coverage, including a local multiprocess locking
  test. The symlink-cleanup and actual permission-denial tests require OS
  capabilities that may be unavailable; shared-filesystem locking has not
  been verified.
- **Priority / impact / effort:** P1; high abuse-resistance impact; M/L.
  Remaining operational concern: deployment-specific filesystem permissions
  and shared-volume locking must be verified by application operators.
- **Deliverables:** Implemented store contract/adapter, middleware,
  deterministic tests, API and security documentation.

### NORA-UPL-01 — Add bounded upload intake and storage adapters

- **Problem / current status:** Implemented `UploadedFile`, `UploadPolicy`,
  the `Request::file()` / `files()` PHP multipart file bag,
  `PrivateFileStorageInterface`, `LocalPrivateFileStorage`, an optional
  `AuthorizedPrivateFileReader` gate, and `PrivateUploadService`. The upload
  service validates batches before writing and removes previous successful
  writes if a later write fails. The adapter verifies its resolved path is
  outside `DOCUMENT_ROOT` when PHP provides that value. Upload metadata and
  ownership persistence remain application-owned.
- **Scope / out of scope:** The implementation validates PHP upload status,
  per-file and aggregate file bytes, detected MIME against an extension/MIME
  allowlist, and stores under random IDs in a private local directory. The
  source file device, inode, and size are rechecked when copied. The reader
  invokes application authorization before opening the identifier. It does
  not implement quotas, retention scheduling, malware scanning, cloud
  storage, transformations, metadata persistence, or public URLs.
- **Dependencies / API:** Depends on SEC-01 upstream limits; `fileinfo` is
  required at runtime. Implemented API:
  ```php
  $stored = $uploadService->store($request->file('document'));
  ```
- **Security / compatibility:** Never use client filenames as storage paths;
  defend against traversal, MIME spoofing, inconsistent source files, and
  authorization bypass on retrieval. A storage identifier must not itself
  grant public access. Document-root enforcement depends on
  `$_SERVER['DOCUMENT_ROOT']`; deployment ACLs and upstream/PHP limits remain
  operational controls.
- **Tests / done when:** Unit tests cover size limits, PHP upload errors,
  malformed metadata, MIME spoofing, traversal-style filenames, randomized
  storage IDs, path identifier rejection, partial cleanup, deletion
  boundaries, PHP-parsed single/multiple request fields, document-root
  rejection, batch cleanup after later storage failure, and
  authorization-before-open behavior. Runtime multipart
  handling against a real HTTP server, OS permission enforcement, large-file
  streaming, and deployment ACLs remain unverified.
- **Priority / impact / effort:** P1; high security and application utility
  impact; L. Uncertainty: cross-platform filesystem semantics and PHP upload
  behavior.
- **Deliverables:** Upload value object/validator, Request file bag, storage
  interface/local adapter, upload coordinator, authorization gate, tests, and API/security docs.
  Framework streaming responses, persistent metadata/ownership, cleanup
  policy, and production multipart/deployment verification remain incomplete.

## Phase 2 — Database capabilities

### NORA-DB-01 — Add safe CRUD query construction

- **Problem / current status:** Implemented `QueryBuilder` factories for
  SELECT, INSERT, UPDATE, and DELETE; live MySQL effects remain unverified.
- **Scope / out of scope:** Mutation values are parameterized; identifiers
  and operators are constrained; updates/deletes require predicates; parameter
  order is deterministic. Affected rows follow PDO driver semantics. Joins,
  arbitrary expressions, relationship loading, and ORM behavior are out of
  scope.
- **Dependencies / API:** Implemented API:
  ```php
  $query = QueryBuilder::insert('users', ['email' => $email]);
  $connection->executeBuilder($query);
  ```
- **Security / compatibility:** Bind every data value; allowlist/validate all
  identifiers; reject empty predicates for destructive updates/deletes unless
  an explicitly named all-rows operation is approved. Do not interpolate
  user-controlled SQL fragments.
- **Tests / done when:** Unit tests assert exact SQL and bindings, invalid
  identifiers and unsupported operations fail closed. Live database tests for
  insert/update/delete effects are pending the deferred MySQL verification.
- **Priority / impact / effort:** P1; high core capability and injection
  prevention impact; M.
- **Deliverables:** CRUD builders, focused unit coverage, API reference, and
  usage example. Live-driver acceptance remains pending.

### NORA-DB-02 — Specify transaction and migration recovery semantics

- **Problem / current status:** `PdoConnection::transaction()` now uses
  savepoints for nested callbacks. A nested rollback failure is logged and
  marks the outer wrapper-managed transaction uncommittable, even if its
  callback catches the nested exception. The migration runner applies and
  reports migrations; `down()` exists but no rollback command is exposed.
  MySQL DDL may auto-commit.
- **Scope / out of scope:** Failed MySQL DDL may leave schema changes without
  a history row. The runner does not perform automatic schema rollback.
  Recovery requires operator inspection/reconciliation before retrying.
  Savepoint behavior has PDO-double coverage; real-driver semantics remain
  unverified locally.
- **Dependencies / API:** Depends on BASE-01 and DB-01 where migration
  operations rely on writes.
- **Security / compatibility:** Never mark a migration as applied before its
  expected operation completes. Preserve the original callback exception
  while reporting rollback failures; do not allow a wrapper-managed outer
  transaction to commit after a failed nested rollback. Destructive rollback
  requires explicit operator intent and a tested backup/restore policy.
- **Tests / done when:** Unit tests cover nested savepoint recovery, exception
  propagation, logging of rollback failure, and outer-transaction rejection
  after failed nested rollback. Existing live MySQL tests cover repeated
  migration runs and failure after DDL but were skipped locally due to no
  available test server. Docs state recovery steps and atomicity limits.
- **Priority / impact / effort:** P1; high data-integrity impact; M.
  Uncertainty: MySQL DDL and MariaDB version behavior.
- **Deliverables:** Savepoint transaction behavior, tests, and recovery
  guidance. Real-driver verification remains pending.

### NORA-DB-03 — Add opt-in query diagnostics

- **Problem / current status:** Implemented as optional
  `QueryDiagnostics`, injected into `PdoConnection` without changing existing
  constructor calls.
- **Scope / out of scope:** Uses monotonic nanoseconds, operation labels,
  aggregate SQL hash/length, and a configurable threshold. It does not retain
  SQL text or bindings and has no dashboard.
- **Dependencies / API:** Implemented API:
  `new QueryDiagnostics($logger, slowQueryThresholdNanoseconds: 50_000_000)`.
- **Security / compatibility:** Disabled by default. Slow diagnostics log
  only operation label, SQL hash, SQL length, and duration; no bindings or raw
  SQL are emitted. Logger failures remain explicit.
- **Tests / done when:** Fake clock/logger tests verify threshold and duration
  behavior and confirm no query values or SQL text appear in diagnostic
  records. No performance benchmark has been run.
- **Priority / impact / effort:** P2; medium debugging/performance impact;
  S/M. Uncertainty: useful threshold and low-overhead event contract.
- **Deliverables:** Implemented diagnostic hook, tests, API documentation, and
  example construction. Performance benchmarking remains unverified.

## Phase 3 — Reactive application development

### NORA-REACT-01 — Define independent nested reactive components

- **Problem / current status:** Implemented `ReactiveParentComponentInterface`,
  `ReactiveChildDefinition`, `ReactiveNestedComponentInterface`, and optional
  `ReactiveComponentLifecycleInterface`. The engine validates props, stores
  isolated child state per session-owned page, assigns random child IDs,
  reconciles unchanged key/type/props deterministically, and disposes removed
  or expired children. Existing root component APIs and root action payloads
  remain compatible.
- **Scope / out of scope:** The implementation supports recursively declared
  child trees, page-local independent state and lifecycle callbacks. It has no
  cross-page shared state, virtual DOM rewrite, computed values, or persistent
  sockets.
- **Dependencies / API:** Implemented as additive interfaces and
  `ReactiveChildDefinition::placeholder($key)`; existing `ReactiveComponent`
  page registrations remain usable unchanged.
- **Security / compatibility:** Child identity and ownership must not be
  accepted from client authority; every child action rechecks authorization,
  CSRF, revision and payload limits. Escape output and cap aggregate
  per-session state/render size.
- **Tests / done when:** Unit/integration tests prove independent state,
  stable child identities, authorization denial, forged and cross-page child
  ID rejection, stale child request rejection, and lifecycle disposal. A local
  Chromium browser test exercises independent child updates and rejects stale,
  forged, and unauthorized requests. A formal supported-browser matrix and
  hosted CI results are not established.
- **Priority / impact / effort:** P1; high UX/security impact; L. Uncertainty:
  component identity, state-sharing semantics, and migration from the current
  experimental API.
- **Deliverables:** Implemented component/props/state contracts, engine and
  runtime changes, lifecycle tests, browser tests, and revised
  `architecture/REACTIVE_UI_ENGINE.md`.

### NORA-REACT-02 — Add server-authoritative reactive forms

- **Problem / current status:** Implemented as an opt-in browser convention
  over the existing reactive action endpoint. Forms collect only explicitly
  bound fields; Nora validates the action allowlist on the server and returns
  field errors on 422. Accessible field feedback and pending, invalid,
  success, and failed states are locally browser-tested.
- **Scope / out of scope:** Build on existing `Validator` and action argument
  validation. Define allowlisted fields, server-side normalization rules,
  error-to-field mapping, accessible summaries/announcements, submission
  pending/failure states, and a non-JavaScript POST fallback where practical.
  Do not trust browser validity or client-submitted state. Reactive file
  bindings remain unsupported; upload intake is a separate API.
- **Dependencies / API:** REACT-01's state/props contract and existing
  `Validator` are implemented. The actual API is HTML attributes plus the
  existing action contract:
  ```php
  // In render(): use data-nora-form, data-nora-action, and bind each
  // supported control with matching name and data-nora-field attributes.
  'save' => \Nora\Reactive\ReactiveAction::publicAction(
      ['email' => 'required|email'],
      static fn (array $state, array $arguments): array => [
          ...$state,
          'email' => $arguments['email'],
      ],
  ),
  ```
- **Security / compatibility:** Every submission uses the same server-side
  action validation, authorization, CSRF/origin, request-size, and revision
  checks as other reactive actions. Unknown arguments are rejected. The runtime
  uses text nodes for validation messages, rejects file controls, and does not
  echo submitted values in protocol errors. Application transitions must not
  retain passwords or tokens in rendered state. Controls with
  `data-nora-field` must be supported input/select/textarea elements whose
  `name` matches the binding; multiple selects and file inputs are unsupported.
- **Tests / done when:** A local Chromium test verifies invalid/valid
  submission, unknown-field rejection, accessible field errors and summary,
  pending/success/failure states, keyboard submission, focus/value
  preservation, and absence of password echo. Existing integration tests
  exercise the shared CSRF, stale revision, action authorization, and unknown
  argument protections. The framework does not provide a separate native POST
  handler: with JavaScript disabled, the form's `method`/`action` performs a
  normal application route submission, which the application must implement
  and validate independently. A no-JavaScript POST route was not browser-tested.
- **Priority / impact / effort:** P1; high form usability and security impact;
  M/L. Uncertainty: accessible component design and progressive enhancement.
- **Deliverables:** Implemented bound form handling in the browser runtime,
  reuse of the server action validator, accessible error feedback, browser
  fixture, and API/architecture documentation. The native POST fallback remains
  an application responsibility; cross-browser accessibility and hosted CI
  results are not established.

## Phase 4 — Authentication ecosystem

### NORA-AUTH-01 — Define and implement an account-provider boundary

- **Problem / current status:** Nora has `PasswordHasher`, `SessionManager`,
  `SessionAuthenticator`, `AuthenticatorInterface`, `AuthenticatableInterface`,
  CSRF and `Gate`. `SessionAuthenticator` delegates lookup to an application
  callback; there is no user repository, account lifecycle, registration,
  credential verification flow, or policy registry tied to accounts.
- **Scope / out of scope:** Define the minimum account identity/repository
  contract and session login/logout flow, password hashing/rehash policy,
  account status, session rotation/invalidation, authorization integration,
  and endpoint-independent service layer. Keep persistence application-owned
  or define a small adapter; do not force a schema or user model on all Nora
  applications. Password reset and email verification are AUTH-02.
- **Dependencies / API:** Depends on verified DB transactions and rate
  limiting (DB-01/02, SEC-02) before built-in persistence endpoints.
- **Security / compatibility:** Use native password APIs with supported
  algorithms, generic credential errors, login throttling, session rotation,
  CSRF protection, safe account enumeration policy, and deny-by-default
  authorization. Avoid requiring database access for the framework core.
- **Tests / done when:** Tests cover correct/incorrect credentials, disabled
  accounts, session fixation, logout invalidation, stale user resolution,
  authorization denial, timing-sensitive enumeration policy, and abuse limits
  against a disposable store.
- **Priority / impact / effort:** P1; high application capability/security
  impact; L. Uncertainty: persistence and identity extensibility.
- **Deliverables:** Account-service contracts, optional persistence adapter
  or documented application adapter, security tests, and API docs.

### NORA-AUTH-02 — Add verification and account recovery flows

- **Problem / current status:** No email verification, password reset, mail
  abstraction, expiring credential token store, or recovery flow exists.
- **Scope / out of scope:** Define an optional mail-sender interface; issue
  cryptographically random, hashed-at-rest, purpose-bound, expiring,
  single-use tokens; implement verification and password-reset service
  workflows; invalidate relevant sessions after credential changes. Provider
  delivery, email templates, and background queues remain adapters.
- **Dependencies / API:** Depends on AUTH-01, SEC-02, a durable token store,
  and maintainer-approved token lifetime and account-enumeration policy.
- **Security / compatibility:** One-time tokens must be hashed at rest,
  constant-time checked, single-use under concurrent requests, scoped to one
  account/purpose, rate-limited, and excluded from logs/URLs after redemption.
  Return generic responses where account existence could be revealed.
- **Tests / done when:** Unit and integration tests verify expiry, replay,
  concurrent use, token-purpose mismatch, account enumeration behavior,
  delivery failure, throttling, and session invalidation.
- **Priority / impact / effort:** P1; high account safety and recovery impact;
  L. Uncertainty: mail delivery/retry and transactional token consumption.
- **Deliverables:** Mail contract, token repository, workflow services,
  tests, configuration and security documentation.

### NORA-AUTH-03 — Add optional two-factor authentication

- **Problem / current status:** No two-factor authentication or recovery-code
  support exists. This capability must not be confused with the current
  session authenticator.
- **Scope / out of scope:** Only after AUTH-01/02, specify an optional TOTP
  factor, enrollment confirmation, replay-resistant time-step policy, hashed
  one-time recovery codes, disable/reset flow, and trusted-device policy if
  justified. Keep it opt-in and adapter-friendly. Defer SMS and mandatory MFA.
- **Dependencies / API:** Depends on AUTH-01 and an approved recovery/support
  policy; external crypto libraries require separate dependency/license
  review.
- **Security / compatibility:** Protect enrollment secrets at rest, never log
  codes, rate-limit guesses, rotate recovery codes, require recent
  reauthentication for factor changes, and prevent recovery from becoming a
  weaker bypass.
- **Tests / done when:** Tests cover valid/invalid codes, time drift window,
  repeated code behavior, recovery-code single-use, factor reset, lockout
  recovery, and session policy.
- **Priority / impact / effort:** P2; high security impact for applications
  that opt in; L. Uncertainty: secret encryption/key management and support
  burden.
- **Deliverables:** Optional MFA service/adapter, recovery-code store, security
  tests, setup/recovery documentation.

### NORA-AUTH-04 — Offer optional Nora Auth scaffolding

- **Problem / current status:** The CLI safely scaffolds a basic project and
  generates controllers/models, but it does not generate authentication
  workflows. A “Nora Auth” product/package name and boundary are undecided.
- **Scope / out of scope:** Once AUTH-01/02 are stable, decide between a
  versioned optional package and an opt-in CLI generator. Generate explicit,
  reviewable routes/services/views/tests without modifying existing user
  files; keep persistence, mail transport and templates configurable. Do not
  create a core dependency or imply generated auth is production-secure
  without the same test and review criteria.
- **Dependencies / API:** Depends on AUTH-01/02; requires package naming,
  ownership, license scope, and support-policy approval.
- **Security / compatibility:** Validate names/paths, refuse overwrite,
  preserve CSRF and safe session defaults, keep secrets out of generated
  files, and test the scaffold in a fresh consumer.
- **Tests / done when:** Snapshot and behavior tests verify generated code
  installs and passes tests, invalid names/overwrite are safely rejected, and
  dependency/license metadata is accurate.
- **Priority / impact / effort:** P2; medium adoption impact; M/L.
  Uncertainty: long-term maintenance of generated code and API drift.
- **Deliverables:** Package or generator, generated-project tests, templates,
  installation guide, and support policy.

## Phase 5 — ORM and developer experience

### NORA-ORM-01 — Evaluate relationships and a minimal ORM

- **Problem / current status:** The framework has no model or relationship
  abstraction. `make:model` only generates a class stub; current database
  architecture explicitly describes `QueryBuilder` as SELECT-only.
- **Scope / out of scope:** After DB-01 through DB-03, run a design spike that
  compares keeping explicit repositories/query objects with a small
  record/repository mapper. If justified, implement only a minimal model
  identity and explicitly declared belongs-to/has-many relationships with
  bounded eager loading. Do not begin with lazy loading, polymorphic
  relationships, schema magic, or a full active-record API.
- **Dependencies / API:** Depends on CRUD, transaction, migration, profiling,
  and real-driver coverage. Requires a maintainer decision that ORM scope is
  worth its compatibility and maintenance cost.
- **Security / compatibility:** Prevent mass assignment by explicit writable
  fields; bind values and validate identifiers; detect N+1 queries; avoid
  implicit database access during rendering; maintain transaction boundaries.
- **Tests / done when:** A measured design decision is documented; if
  implemented, tests cover relation cardinality, missing/deleted records,
  eager-load query counts, mass assignment rejection, transaction rollback,
  and MySQL behavior.
- **Priority / impact / effort:** P2; potentially high productivity impact;
  XL. Uncertainty: API surface, migration cost, and whether users prefer
  explicit SQL/repositories.
- **Deliverables:** Decision record first; only then model/relation code,
  integration tests, profiling assertions, and migration guidance.

### NORA-DEVX-01 — Prepare Nora Template extension distribution

- **Problem / current status:** The extension source recognizes `.nora`, has
  a TextMate grammar, snippets, icons, and a validator tested against the
  website templates. A VSIX can be packaged and installed locally. Marketplace
  publisher ownership and extension licensing are not verified; the extension
  manifest currently uses `UNLICENSED`.
- **Scope / out of scope:** Confirm extension license/asset rights, publisher
  ownership and availability, package metadata, version/changelog, and release
  process. Continue validating/package inspection in CI. Do not publish
  automatically or claim Marketplace availability before a verified release.
- **Dependencies / API:** Requires maintainer approval for extension license
  scope and verified publisher identity; no framework runtime dependency.
- **Security / compatibility:** Keep credentials out of manifests/workflows;
  inspect packaged contents; make no language-server/IntelliSense claims
  unless those capabilities are subsequently implemented and tested.
- **Tests / done when:** Manifest and paths validate, supported `.nora`
  grammar examples are checked, VSIX contains required files and no generated
  nested VSIX/secrets, isolated install succeeds, and Marketplace identity
  and release status are explicitly recorded.
- **Priority / impact / effort:** P2; moderate developer adoption impact;
  S/M. Uncertainty: publisher registration and legal rights to visual assets.
- **Deliverables:** Extension license and metadata decision, validation CI,
  packaging instructions, and a release checklist. Publish the VSIX as an
  approved GitHub Release asset rather than tracking it in source.

## Phase 6 — Adoption and ecosystem

### NORA-DOC-01 — Establish French and Arabic documentation workflow

- **Problem / current status:** Core documentation is in English; French and
  Arabic translations are planned but not available. No translation workflow
  or reviewed terminology list was found.
- **Scope / out of scope:** Stabilize the English source pages first; identify
  a small onboarding/security subset; add a translation status/date/source
  revision marker and native review process. Do not machine-publish
  unreviewed safety or API guidance as authoritative.
- **Dependencies / API:** Depends on accurate, stable English requirements
  and reviewer availability for both languages.
- **Security / compatibility:** Keep warnings, security requirements,
  commands, and version requirements semantically aligned; mark stale
  translations and link back to the canonical English text.
- **Tests / done when:** Each translated page is reviewed by a fluent
  contributor, links and code commands are checked, source revision is
  recorded, and a process flags outdated translations.
- **Priority / impact / effort:** P2; high inclusion/adoption value; M.
  Uncertainty: sustainable fluent review capacity.
- **Deliverables:** Translation guide/glossary, localized onboarding pages,
  review ownership, and a freshness checklist.

## Recommended first milestone

Start with three bounded issues; they close the largest evidence gaps without
adding new framework APIs:

1. **NORA-BASE-01 — Verify database behavior against a real server.**
   Acceptance: the tracked MySQL 8.4 CI service runs write, rollback, migration
   status, repeat-run, failure, and cleanup tests without a skipped database
   suite; supported DB claims match the verified matrix.
2. **NORA-BASE-02 — Automate clean package-consumer verification.**
   Acceptance: CI builds and inspects a Composer archive, installs it in a
   clean temporary consumer, verifies the documented bootstrap route and
   installed CLI exit code, and checks runtime assets/exclusions without
   Packagist or publishing credentials.
3. **NORA-SEC-01 — Close request and rendering security-boundary gaps.**
   Acceptance: the request-body ingress limit and trusted-rendering boundary
   have explicit deployment guidance and regression coverage; an independent
   security-review scope and remediation process are agreed before any
   production-readiness claim.

Docker Compose (OPS-01) follows once BASE-01 has a verified service/version,
so local contributors use the same test target as CI. Auth, uploads, and
reactive forms should not be built on unverified data and request boundaries.

### Proposed issue titles

- `[NORA-BASE-01] Cover writes, transactions, and migrations on the CI database`
- `[NORA-BASE-02] Verify Composer archives in a clean consumer project`
- `[NORA-SEC-01] Specify and test request-ingress and trusted-render boundaries`

### First-milestone exit criteria

- All three issues have passing, reproducible CI evidence and focused review.
- Live integration tests do not silently skip in the dedicated DB job.
- Unsupported DB versions, skipped checks, ingress responsibilities, and
  trusted PHP rendering limits are documented accurately.
- No production-readiness or broad database-compatibility claim is added from
  unit tests or a single local run.

## Dependency map

```text
BASE-01 (real DB confidence) ──┬──> OPS-01 (Docker Compose)
                              ├──> DB-01 (CRUD) ──> DB-02 (transaction/migration semantics)
                              │                    └──> DB-03 (query diagnostics)
                              └──> AUTH-01 (durable provider choices)
SEC-01 (ingress/security boundaries) ──┬──> SEC-02 (rate limiting)
                                       ├──> UPL-01 (bounded upload/storage)
                                       └──> REACT-01 (nested component security)
REACT-01 + existing Validator ────────────> REACT-02 (reactive forms)
AUTH-01 + SEC-02 + token/mail decisions ─> AUTH-02 (verification/recovery)
AUTH-01 + recovery policy ───────────────> AUTH-03 (optional 2FA)
AUTH-01 + AUTH-02 + package decision ────> AUTH-04 (optional scaffold)
DB-01 + DB-02 + DB-03 ───────────────────> ORM-01 (relationship/ORM decision)
Stable English docs + fluent reviewers ─> DOC-01 (French/Arabic docs)
Extension license/publisher decisions ─> DEVX-01 (distribution)
```

Arrows are prerequisite relationships, not promises that every upstream task
must produce a particular implementation. A design spike may recommend against
an ORM, 2FA, or a standalone Auth package.

## Maintainer decisions required

1. **Database support:** Is the supported promise MySQL only, or MySQL and
   MariaDB? Which versions and CI matrix are sustainable?
2. **Transactions/migrations:** Should nested transactions use savepoints,
   reject nesting, or preserve current participation semantics? Is migration
   rollback supported, and what recovery is promised after MySQL DDL failure?
3. **Request boundary:** Which limits are guaranteed by Nora versus PHP,
   reverse proxy, or deployment configuration? What is the default body limit?
4. **Storage:** Is local private filesystem the only first-party backend?
   Which retrieval/visibility semantics are required? Are malware scanning and
   object storage strictly external adapters?
5. **Rate limiting:** Which backend is the maintained baseline, what happens
   on backend outage, and which proxy headers may be trusted?
6. **Authentication:** Does core remain provider-neutral with an optional
   Auth package? Which account schema, mail transport, token lifetimes,
   enumeration policy, and recovery responsibilities are acceptable?
7. **Reactive API:** What are the state/props/component identity and migration
   guarantees before stabilizing the current experimental prototype?
8. **ORM:** Is a higher-level mapper worth its API and maintenance cost, or
   are query objects and repositories sufficient?
9. **Distribution and legal scope:** The root MIT license notice names
   Morave Technologies, as confirmed by the maintainer. The website Composer
   project and VS Code extension currently declare their own license values;
   decide their scope separately. Verify Marketplace publisher ownership
   before any publication.
10. **Localization:** Which pages are first, and who can review French and
    Arabic technical/security terminology?

## Explicitly deferred until fundamentals are stable

- A full ORM, implicit lazy loading, polymorphic relations, and schema magic.
- Mandatory 2FA, SMS delivery, social login, and a turnkey user database.
- Public-by-default upload storage, built-in malware scanning, or cloud
  vendor-specific clients in framework core.
- Reactive forms built around client-authoritative state, automatic mutation
  retries, or persistent sockets without a demonstrated requirement.
- Claims that rate limiting replaces ingress, network, or resource controls.
- Production-readiness, broad database-driver, browser-support, or security
  certification claims based only on unit tests.
- Automated Packagist or VS Code Marketplace publishing.

## Criteria for reconsidering production-readiness claims

Reconsider such claims only after the project has: reproducible install and
upgrade evidence from a clean distribution artifact; an explicitly supported
PHP/database matrix with real integration results; tested migration and
transaction failure recovery; documented request, session, upload, storage,
and reverse-proxy boundaries; security regression tests for authentication,
authorization, CSRF, rate limits, uploads, and reactive state; an independent
security review with resolved or transparently accepted findings; stable API,
support, and deprecation policies; verified license scope and
contribution/third-party notices; and operations/documentation that an external
maintainer can execute.

This gate is a review checklist, not a security guarantee or a substitute for
application-specific threat modeling.
