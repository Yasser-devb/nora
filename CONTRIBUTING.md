# Contributing

Nora is created and maintained by Morave Technologies in Morocco. We aim to
build an independent, modular PHP framework in the open, with contributors
from Morocco and everywhere else.

## Participation and licensing status

Anyone may discuss ideas, file issues, and propose code or documentation
changes through a pull request. Every proposal is subject to maintainer review;
submission does not guarantee acceptance or merging. Public repository
visibility or GitHub's fork feature does not itself grant copyright permission
to use, modify, or redistribute the code.

**No project reuse license has yet been approved or published.** `composer.json`
currently identifies the package as proprietary. Until the authorized rights
holder confirms they may license the project code, approves a license, and the
project publishes it, do not assume there is permission to redistribute or
use Nora code. The proposed MIT license is a recommendation only.

Once a project license and contribution terms are published, contributors
must have the rights needed to submit their work and must respect that license
and all third-party licenses. Identify copied or adapted material and its
license; do not submit material with incompatible terms or unclear
provenance. The project is not introducing a CLA or copyright assignment.
Contributors should not assume they transfer copyright by opening a pull
request.

Community contributions are proposals, not direct writes to the official
repository. Only authorized maintainers decide whether a change is merged into
the official default branch or included in an official release. External
contributors are not given repository write, maintain, or administrator access
by default.

## Ways to participate

- Use the bug-report template for reproducible defects.
- Use the feature-request template to discuss the problem and expected
  behavior before implementation.
- Use a draft pull request for work that needs design feedback.
- Use the designated private channel in [SECURITY.md](SECURITY.md) for security
  reports; never disclose vulnerabilities in public issues. Verify that
  GitHub private vulnerability reporting is enabled before relying on it.
- Treat everyone in accordance with the [Code of Conduct](CODE_OF_CONDUCT.md).

## Development setup and checks

Requirements: PHP 8.2+, Composer 2, PDO, and `pdo_mysql` for database-related
work. The current CI matrix tests PHP 8.2 and 8.3.

```powershell
composer install
composer validate --strict
composer test -- --display-warnings
```

Real database changes should also be tested against a supported MySQL/MariaDB
service. Report an unavailable database as blocked, not passed. Do not include
credentials, `.env` files, generated vendor files, or test artifacts.

## Pull requests

1. Open or link an issue when the change is substantial; agree on scope before
   investing in a large feature.
2. Keep changes focused and preserve existing behavior unless a behavior
   change is intentional and documented.
3. Add or update tests for behavioral changes. Update user and architecture
   docs to match implemented behavior; label unverified work honestly.
4. Run Composer validation, the relevant tests, and PHP syntax checks. Include
   exact commands and results, including skipped or blocked checks.
5. Explain compatibility impact, security implications, and any migration or
   operational concerns in the pull-request template.
6. Do not bundle unrelated formatting or generated files.

The project identifies `Yasser-devb/nora` as its official repository. Its
local development branch is `main`; the actual GitHub default-branch setting
has not been verified. See [RELEASE_POLICY.md](RELEASE_POLICY.md) for proposed
branch protection, tag protection, review, and release controls. These are
recommendations until configured by the repository administrator.

The project is pre-release: public API changes may be breaking, but must be
identified in the changelog and release notes. Community forks may evolve
independently, but must not be represented as maintainer-approved Nora updates.

## Architecture

Preserve the modular boundaries in the
[system overview](architecture/SYSTEM_OVERVIEW.md). Keep core changes
independently testable, use typed contracts, avoid global service lookup, and
do not introduce Laravel or Symfony as underlying frameworks. Propose runtime
dependencies with their purpose, maintenance status, license, and impact on
the minimum PHP version.
