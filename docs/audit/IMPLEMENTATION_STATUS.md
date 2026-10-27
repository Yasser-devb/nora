# Nora Implementation Status

**Updated:** 2026-10-27

| Area | Status | Evidence / limitation |
|---|---|---|
| Initial repository audit | Implemented | Original empty-repository state recorded in `INITIAL_AUDIT.md` |
| Composer PSR-4 package | Implemented, tested | `composer validate --strict`; local Composer install |
| Bootstrap and request lifecycle | Implemented, tested | Unit/integration tests; generated app served over HTTP with status 200 |
| Configuration and `.env` loading | Implemented, tested | Layer precedence, required/type checks, process-variable precedence |
| Exception handling/logging | Implemented, tested | Safe response mapping and structured redaction tests |
| Validation | Implemented, tested | Unit tests for built-in/custom rules and nested fields |
| PDO/query/migrations | Implemented, unit tested | PDO doubles; `pdo_mysql` is enabled locally, but no MySQL/MariaDB server is listening on localhost:3306 |
| CLI | Implemented, tested | Help/version, scaffold, generators, safe overwrite behavior; `serve` delivered a clean generated reactive app |
| Sessions/auth/authorization/CSRF | Implemented, tested | Unit and security integration tests; no account provider or rate limiter |
| View rendering | Implemented, tested | Escaped values, includes, path/cycle guards |
| Reactive UI prototype | Experimental, tested | Authorization callback omission denies by default; `ReactiveAction::publicAction()` is explicit public opt-in. PHP regressions verify missing/denied policies do not invoke transitions, authorized policy succeeds, and strict action/payload validation rejects forged input. Browser tests still cover the generated allow/deny/invalid-action flow. One page component only; no nested reactive components or independent shared-state protocol |
| Nora website example | Implemented, tested locally | Standalone Nora app under `examples/nora-website`; ten content routes, native responsive navigation, escaped 404, safe branded exception responses, an interactive homepage counter, and a tested experimental playground. No deployment/public URL is claimed |
| Architecture documentation | Implemented | Current component/flow docs; diagrams describe current behavior |
| CI | Workflow tracked; hosted execution not verified | The workflow includes PHP tests, audits, browser tests, and an ephemeral MySQL 8.4 service. No hosted Actions run is verified by this status |
| Open-source governance | Partially prepared | Framework code is MIT-licensed and the root copyright notice names Morave Technologies, as confirmed by the maintainer. The website example and VS Code extension have separate license declarations. Private security reporting and branch protection need platform setup |
| Project comparison | Scoped research documented | GitHub search surfaced Moroccan PHP community, integration package, and Laravel application examples; review is not exhaustive and supports no “first” claim |
| AI integration | Planned | No provider/tool runtime exists |
| Linux web-server deployment | Unverified | No Linux, Apache, or Nginx environment used |
| Performance benchmarks | Unverified | No benchmark harness or measurements |
| Independent security review | Not performed | Required before production use |

## Verification run locally

- Windows environment: PHP 8.2.34 (64-bit), Node 24.20.0, npm 11.19.0;
  `PDO` and `pdo_mysql` are enabled.
- `composer test -- --display-warnings`: **PASS**, 72 tests and 289
  assertions; **SKIPPED**, one live MySQL test because `NORA_TEST_DSN` was
  unset. Existing PDO unit tests ran. The new fallback/error response tests
  are included.
- `php -l` over PHP files in `src`, `tests`, `bin`, and the website app: **PASS**
  (64 PHP files/scripts, including `bin/nora`).
- `composer validate --strict`: **PASS** for the root and website manifests.
- `composer audit`: **PASS** for the root and website dependencies; no
  advisories reported.
- `node --check tests/browser/nora-website.test.js` and
  `node --check resources/reactive/runtime.js`: **PASS**.
- `npm audit`: **PASS**, zero vulnerabilities.
- `npm run test:browser`: **PASS**, two Playwright Chromium scenarios against
  a clean generated app and the Nora website, served by PHP's Windows
  development server.
- Local Markdown link verification: **PASS**, 30 Markdown files and 56 local
  links checked with no missing targets.
- GitHub Actions: **NO PASSING HOSTED RUN VERIFIED IN THIS STATUS UPDATE**.
  `.github/workflows/ci.yml` is tracked in the repository and configures PHP,
  browser, extension-validation, audit, and disposable MySQL 8.4 checks. This
  status does not establish whether the current hosted workflow has run or
  passed; no hosted result should be inferred from local configuration.
- Nora Framework's code is distributed under MIT; the root `LICENSE` notice
  reads `Copyright (c) 2026 Morave Technologies`, as confirmed by the
  maintainer. The website example and VS Code extension retain separate
  license declarations pending a scope decision.
- Private vulnerability reporting and branch protection have not been
  verified through repository settings. Issue and pull request templates,
  contribution guidance, and the security policy exist.
- Reactive UI support is experimental and unstable. The browser test exercises
  one Chromium version only. There are no independent nested components,
  cross-component state ownership, reactive forms, navigation, automatic
  retries, persistent transport, or formal browser support matrix.
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
- The framework has no independent security audit, account provider, general
  rate limiter, deployment hardening guide, or durable idempotency guarantee
  for external effects. Automated checks are not a security certification.
- License: Nora Framework's code is distributed under MIT, and the root
  copyright notice names Morave Technologies as confirmed by the maintainer.
  The website example and VS Code extension retain separate license
  declarations that need independent scope review.
- Private GitHub vulnerability reporting is **NOT VERIFIED**; no dedicated
  monitored contact is configured. Repository branch/tag protection and
  permission settings are also **NOT VERIFIED**.

## Nora website example

The separate application at `examples/nora-website` is implemented with
Nora's `ApplicationBootstrap`, routing and 404 fallback, safe HTML responses,
escaped `.nora` templates, and an experimental `ReactiveEngine` component.
It serves the requested overview, feature, documentation, architecture,
playground, examples, roadmap, changelog, community, and contact routes. The
homepage itself is a reactive page with PHP action source, live increment/reset
controls, a three-step workflow explanation, and a verified capability status
table. Its counter is session-owned and explicitly public to the page holder.
The playground demonstrates one explicitly public counter action, server-validated
arguments, an explicitly denied protected action, and a server-authorized tab
selection. Unexpected request exceptions use Nora's centralized logger and a
safe branded response factory; user-controlled exception details are not
rendered.

The website's browser scenario checks all ten content routes, native mobile
navigation and horizontal overflow, desktop navigation, security response
headers, a branded escaped 404 and safe branded 500 regression, homepage
runtime loading and the PHP counter's increment/reset without navigation, and
the playground's successful updates, retained focus, invalid arguments (422),
denied action (403), and unregistered action (404) without state mutation. It
also checks that the website loads no third-party assets. This app has no text
input, so it does not claim to test input-value or selection preservation.
Reactive forms,
field-level errors, navigation, auth identity, nested components, and shared
state remain unimplemented. Static pages use a local stylesheet and do not
depend on external frontend frameworks, fonts, or CDNs. The site has no
configured contact form/service; its license and GitHub security-reporting
statements remain pending/qualified as noted above.

The verification results above include the website changes and were run on
Windows with PHP 8.2.34 / Node 24.20.0. The sample app's input-value,
selection, and keyed-node preservation checks remain covered by its separate
browser scenario; the website playground has no text inputs.

## Reactive UI acceptance checklist

| Criterion | Result | Test evidence |
|---|---|---|
| PHP component renders correctly | PASS | Generated Chromium page asserts heading and keyed sections; website test asserts PHP-rendered hero, action preview, and live counter |
| Nora runtime loads automatically | PASS | Mounted HTML script element and runtime asset HTTP 200 asserted on both generated app and website home |
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
