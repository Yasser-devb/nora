# Nora Framework v0.1.1

Nora Framework v0.1.1 is a pre-1.0 maintenance release.

## Changes

- Rate-limited requests that exceed their limit now receive HTTP 429 before
  downstream handlers execute. The response retains the rate-limit metadata
  headers, including `Retry-After`.
- Regression coverage verifies rejected requests do not invoke their handler.
- Migration status and execution ordering are documented as lexicographical
  filename order. Migration tests use deterministic fixtures and retain
  failure-history assertions.

## Requirements and installation

- PHP 8.2 or newer and Composer 2.
- PDO with `pdo_mysql` for the supported MySQL/MariaDB baseline.

The Composer package name is `morave/nora`; Packagist availability and a clean
consumer install have not been verified. Follow the repository's
[installation instructions](../README.md) for source-checkout testing.

## Verification

Passed:

- `composer validate --strict`
- `composer audit --locked`
- `npm audit`
- `composer test -- --display-warnings --display-skipped` — 121 tests,
  532 assertions, 4 skipped, no failures
- Reactive lifecycle tests — 12 tests, 127 assertions
- PHP syntax checks on modified PHP files
- `git diff --check`

Not verified:

- MySQL migration integration behavior. The test was skipped because no
  dedicated disposable `nora_test` database and test credentials were
  configured.
- Unix filesystem permission enforcement. The test was skipped because the
  Windows environment allowed writes after changing directory permissions;
  no Linux/WSL environment was available.
- The symlink cleanup test was skipped because this Windows environment did
  not permit creating symbolic links.
- Semgrep and Gitleaks reruns; their executables were unavailable.

Automated tests do not certify the framework as secure or production-ready.
