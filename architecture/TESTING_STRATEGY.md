# Testing Strategy

PHPUnit is configured in `phpunit.xml` with `Unit`, `Integration`, and
`Security` suites. Tests check observable behavior including routing,
middleware, configuration, error mapping, logger redaction, validation,
parameter binding, transaction decisions, migration status, CLI file safety,
session flags, CSRF, password hashing, authorization, and HTML escaping.
Reactive integration tests additionally cover page/session ownership,
action allowlists and validation, authorization, CSRF/origin checks,
revision replay protection, size limits, and escaped component output.

Run:

```powershell
composer validate --strict
composer test -- --display-warnings
```

CI is configured for PHP 8.2 and 8.3. PDO tests currently use a test double
because this environment has the `pdo_mysql` extension but no reachable local
MySQL/MariaDB service. Run integration tests against a real supported database
before relying on migrations in production. Static analysis and performance
benchmarks are not configured.

Set `NORA_TEST_DSN`, `NORA_TEST_DB_USERNAME`, and `NORA_TEST_DB_PASSWORD` to
enable the read-only MySQL parameter/transaction integration test. It is
skipped when no test DSN is supplied.

The generated project includes the reactive counter slice. A separate
Playwright test (`npm ci`, `npx playwright install chromium`,
`npm run test:browser`) creates a clean scaffold and verifies real browser
actions, no page navigation, shared output, input/focus preservation, and
keyed node identity. Hosted Chromium CI is configured but must be confirmed by
a GitHub Actions run. Browser coverage does not validate production deployment
or a real database.
