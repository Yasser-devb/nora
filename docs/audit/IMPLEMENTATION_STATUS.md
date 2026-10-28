# Nora Implementation Status

**Updated:** 2026-10-28

| Area | Status | Evidence / limitation |
|---|---|---|
| Initial repository audit | Implemented | Original empty-repository state recorded in `INITIAL_AUDIT.md` |
| Composer PSR-4 package | Implemented, tested | `composer validate --strict`; local Composer install |
| Bootstrap and request lifecycle | Implemented, tested | Unit/integration tests; generated app served over HTTP with status 200 |
| Configuration and `.env` loading | Implemented, tested | Layer precedence, required/type checks, process-variable precedence |
| Exception handling/logging | Implemented, tested | Safe response mapping and structured redaction tests |
| Validation | Implemented, tested | Unit tests for built-in/custom rules and nested fields |
| PDO/query/migrations/diagnostics | Implemented, tested | CRUD builders, nested savepoints, migration tracking, and opt-in query diagnostics; MySQL 8.4 integration passed in hosted CI at `2183336`; local DB and MariaDB remain unverified |
| CLI | Implemented, tested | Help/version, scaffold, component generator, safe overwrite behavior; `serve` delivered a clean generated reactive app |
| Sessions/auth/authorization/CSRF | Partially implemented, tested | Session integration plus provider-based password login, account status checks, generic credential rejection, password rehash, and required rate limiting; registration, persistence schema, verification/reset, MFA, and account lifecycle remain absent |
| Rate limiting | Experimental, opt-in | File-backed fixed-window store and middleware have unit coverage; single-host/shared-filesystem locking and deployment permissions require verification |
| Upload intake and private storage | Partial, unit tested | Bounded PHP upload validation, MIME policy, private local storage, and authorization-before-read; live multipart behavior, deployment ACLs, metadata, and large-file streaming remain unverified |
| View rendering | Implemented, tested | Escaped values, includes, path/cycle guards |
| Reactive UI | Experimental, tested | Composable `Component`/`UI`/`SafeHtml` API, typed props, named slots, reusable components, bundled theme, demo, and existing explicit authorization/CSRF/revision/nested-state/form behavior have PHP and local Chromium coverage; API remains unstable and is not production certified |
| Nora website example | Excluded from framework release | Local standalone project remains on disk; its implementation and website-specific tests are not part of the framework source tree or Composer package |
| Architecture documentation | Implemented | Current component/flow docs; diagrams describe current behavior |
| CI | Workflow tracked; release changes not yet run in hosted CI | Latest prior run at `2183336` passed PHP 8.2/8.3, package-consumer, browser, and MySQL 8.4 jobs; this release's UI changes have local test coverage only |
| Open-source governance | Partially prepared | Framework code is MIT-licensed and the root copyright notice names Morave Technologies, as confirmed by the maintainer. The separate extension declares `UNLICENSED`; private security reporting and branch protection need platform setup |
| Project comparison | Scoped research documented | GitHub search surfaced Moroccan PHP community, integration package, and Laravel application examples; review is not exhaustive and supports no “first” claim |
| AI integration | Planned | No provider/tool runtime exists |
| Linux web-server deployment | Unverified | No Linux, Apache, or Nginx environment used |
| Performance benchmarks | Unverified | No benchmark harness or measurements |
| Independent security review | Not performed | Required before production use |

## Verification run locally

- Windows environment: PHP 8.2.34, Node 24.20.0, npm 11.19.0; `PDO`,
  `pdo_mysql`, and `zip` are enabled.
- `composer validate --strict`: **PASS**.
- `composer audit`: **PASS**, no advisories reported.
- `composer check`: **PASS**, including strict Composer validation, Composer
  audit (no advisories), and **142 tests / 667 assertions; 4 SKIPPED**.
  Skips: filesystem permission semantics and symlink creation are unsupported
  in this process/platform, and two MySQL integration tests require a
  dedicated `NORA_TEST_DSN`.
- PHP syntax checks over changed and new framework PHP files and
  `node --check` over the reactive runtime and browser test: **PASS**; PHP
  syntax was checked on 66 source, example, and test files.
- `npm run test:browser`: **PASS**, four Playwright Chromium scenarios,
  including generated applications, nested components, and the UI demo.
- GitHub Actions at `2183336`: **PASS**, including both MySQL-backed PHP
  matrix jobs; it predates the UI changes documented here.
- Independent security review: **NOT PERFORMED**; the planned review remains a
  release gate. Passing automated checks are not a security certification.
- Nora Framework's code is distributed under MIT; the root `LICENSE` notice
  reads `Copyright (c) 2026 Morave Technologies`, as confirmed by the
  maintainer. The website example and VS Code extension retain separate
  license declarations pending a scope decision.
- Private vulnerability reporting and branch protection have not been
  verified through repository settings. Issue and pull request templates,
  contribution guidance, and the security policy exist.
- Reactive UI support and its public component API are experimental and
  unstable. Browser tests exercise one Chromium version only. Navigation/
  history, automatic retries, persistent transport, and a formal browser
  support matrix are not provided. No automated accessibility scanner or
  independent security audit has been performed.
- Generated project: the test runs `nora new`, installs its Composer path
  dependency, serves it with PHP's development server, and drives the real
  rendered page in Chromium.
- MySQL: **BLOCKED / UNVERIFIED**. `Test-NetConnection localhost -Port 3306`
  returned `TcpTestSucceeded: False`; no MySQL/MariaDB process was found.
  `tests/Integration/DatabaseServerTest.php` requires a dedicated test
  database connection via `NORA_TEST_DSN`, `NORA_TEST_DB_USERNAME`, and
  `NORA_TEST_DB_PASSWORD`. Example PowerShell setup for an already provisioned
  local test database:

  ```powershell
  $env:NORA_TEST_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=nora_test;charset=utf8mb4'
  $env:NORA_TEST_DB_USERNAME = 'nora_test_user'
  $env:NORA_TEST_DB_PASSWORD = '<local-test-password>'
  composer test -- --display-warnings
  ```

  To provision a **local disposable** database on a running MySQL/MariaDB
  instance, connect as a local database administrator with
  `mysql --host=127.0.0.1 --user=root --password`, then execute:

  ```sql
  CREATE DATABASE nora_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'nora_test_user'@'127.0.0.1' IDENTIFIED BY '<local-test-password>';
  GRANT SELECT ON nora_test.* TO 'nora_test_user'@'127.0.0.1';
  ```

  Use the same locally chosen password in `NORA_TEST_DB_PASSWORD`. Never point
  these variables at production or an account containing user data. The
  integration test performs a parameterized `SELECT` and checks rollback of a
  read-only transaction; it runs no migrations or destructive SQL.

## Security review and known limitations

- Reactive actions now deny when no authorization callback is supplied.
  `ReactiveAction::publicAction()` is an explicit opt-in for a public action
  available to a holder of the current valid page/session. Authorization
  callbacks must be trusted application code and should be side-effect free;
  state transitions execute only after successful authorization.
- The reactive handler rejects request bodies larger than 16 KiB, requires
  the JSON media type, enforces exact top-level request keys, and validates
  declared action arguments. State is capped at 64 KiB, rendered content at
  256 KiB, and each session at 32 page records with a 30-minute inactivity TTL.
- The browser sends no authoritative state/component identifier. Page ownership
  is resolved from the server session; action names are allowlisted; stale
  revisions and replay are rejected. CSRF, same-origin checks, and session
  cookies remain necessary and are tested in the PHP integration suite.
- Component rendering and transition/authorization callbacks are trusted PHP.
  Unsafe unescaped markup in `render()` is not sanitized by the browser's
  `innerHTML` parser. Applications must use context-appropriate escaping and
  must not expose secrets in page state, HTML, or validation errors.
- The 16 KiB reactive-body check occurs after `Request::fromGlobals()` reads
  `php://input` into memory. Production PHP/web-server or reverse-proxy request
  body limits are still required to bound ingress memory use.
- Debug mode intentionally returns exception class/message details for
  unexpected errors; keep `APP_DEBUG=false` in production. Custom validation
  rules and error messages are application-controlled and must not disclose
  secrets.
- The framework has no independent security audit, account provider, or durable
  idempotency guarantee for external effects. File-backed rate limiting is
  opt-in and upload support is partial; deployment hardening and permissions
  remain application/operator responsibilities. Automated checks are not a
  security certification.
- License: Nora Framework's code is distributed under MIT, and the root
  copyright notice names Morave Technologies as confirmed by the maintainer.
  The website example and VS Code extension are separate projects and are not
  relicensed by the framework license. The local extension manifest declares
  `UNLICENSED`.
- Private GitHub vulnerability reporting is **NOT VERIFIED**; no dedicated
  monitored contact is configured. Repository branch/tag protection and
  permission settings are also **NOT VERIFIED**.

## Excluded local website example

The standalone website example and its website-specific tests remain available
in the local workspace but are excluded from the public framework repository.
They are not required to install or test the framework core.

## Reactive UI acceptance checklist

| Criterion | Result | Test evidence |
|---|---|---|
| PHP component renders correctly | PASS | Generated-app Chromium scenarios assert rendered keyed sections and nested component output |
| Nora runtime loads automatically | PASS | Generated-app scenarios assert runtime asset HTTP 200 |
| Declared browser event invokes an explicitly registered PHP action | PASS | Click sends POST, registered `increment` updates output; tampered action gets 404 |
| Server validates request and checks authorization | PASS | Browser receives 422 for invalid amount and 403 at the sample authorization limit |
| Component state changes and relevant DOM updates | PASS | Both outputs update from 0 to 10 through PHP-rendered responses |
| Reactive interaction avoids full-page reload | PASS | Main-frame navigation count stays at initial navigation |
| Input value, focus, selection, and keyed identity preserved where exercised | PASS | Browser asserts draft value, focused button, focused input selection, and keyed node identity |
| Forged action, invalid arguments, and unauthorized request rejected | PASS | HTTP 404, 422, and 403 plus unchanged output are asserted |
| Errors handled safely | PASS | Generic status text and absence of internal paths/stack traces asserted |
| Generated sample reproduces end-to-end flow | PASS | Test scaffolds a fresh application and exercises it in Chromium |

These are narrow prototype acceptance results, not a guarantee for every
application, browser, form control, or component tree.
