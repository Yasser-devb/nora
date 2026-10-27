# Release and Versioning Policy

Nora is pre-release and has no published release or promised maintenance
window. This policy describes the intended process; it does not imply a
release schedule or current support commitment.

## Official repository and authority

The project designates [`Yasser-devb/nora`](https://github.com/Yasser-devb/nora)
as the official Nora repository. Morave Technologies is identified as the
project origin and maintainer. Only releases published by an authorized
maintainer from that repository are official Nora releases. A fork may have
its own changes and releases, but is not an official Nora release and must not
be presented as endorsed or maintained by Morave Technologies unless that
endorsement is explicitly granted.

Pull requests are proposals. Authorized maintainers decide what is merged into
the official default branch and what is included in an official release.
External contributors do not receive repository write, maintain, or
administrator access by default.

These authorities are distinct:

- **Copyright:** The actual copyright holder for each contribution is not
  established by this policy. Contributors do not assign their copyright here.
- **Code permissions:** Nora Framework's code is distributed under the
  [MIT License](LICENSE). It permits license-compliant modified forks; it does
  not require those forks to be merged upstream or remain open source.
- **Repository administration:** GitHub role and ruleset settings control
  access to the official repository. They are not granted by a code license.
- **Official changes and releases:** Only authorized maintainers can decide
  what enters the official branch or publish an official release.
- **Name and branding:** No trademark ownership or registration is asserted
  here; see [TRADEMARKS.md](TRADEMARKS.md) for proposed guidance.

## Recommended GitHub controls (not verified as enabled)

The following are administrative recommendations, not a statement of current
repository settings. The owner or repository administrator should verify and
apply them in GitHub:

1. Confirm the intended default branch (normally `main`) and protect it with a
   ruleset or branch-protection rule.
2. Require pull requests for changes to the default branch and prohibit
   ordinary direct pushes. Require relevant CI checks, including PHP tests,
   Composer validation/audit, and browser tests where applicable.
3. Require at least one review from an authorized maintainer or designated
   code owner where feasible. Configure dismissal of stale approvals and
   approval of the most recent reviewable push according to the team's
   capacity.
4. Restrict bypass and ruleset administration to authorized maintainers.
   Preserve an explicit owner/admin recovery and merge path so the owner is
   not locked out and urgent reviewed fixes remain possible.
5. Protect release tag patterns (for example `v*`) from unauthorized
   creation, update, and deletion where the GitHub plan supports it.
6. Publish GitHub Releases only through the authorized maintainer workflow,
   from reviewed commits in the official repository. Include source tag,
   changelog, compatibility notes, and verification results.

`CODEOWNERS` can request reviews for matching paths only when the repository
enables the relevant code-owner review requirement. It does not grant
permissions, protect branches or tags by itself, or replace repository
administration and access controls.

The public GitHub repository metadata currently reports `main` as the default
branch. Branch protection/rulesets, required checks, reviewers, bypass
permissions, tag protection, and administrator access have not been verified
through repository settings.

## Versioning

Once releases begin, Nora intends to use Semantic Versioning (`MAJOR.MINOR.PATCH`):

- `0.y.z` denotes pre-1.0 development. Public APIs may change; a new minor
  version may include breaking changes, which must be highlighted.
- `1.0.0` will mean the project has met the roadmap's stability, compatibility,
  licensing, documentation, and verification criteria.
- After `1.0.0`, incompatible public API changes require a major version,
  backward-compatible functionality requires a minor version, and
  backward-compatible fixes require a patch version.

Public API includes documented PHP classes and methods, extension interfaces,
CLI commands and their observable behavior, configuration keys, and generated
scaffold conventions.

## Compatibility and support

The minimum PHP requirement is PHP 8.2. CI currently tests PHP 8.2 and 8.3;
other PHP versions and database combinations are not yet declared supported.
Before a stable release, maintainers will publish an explicit tested runtime
matrix and support policy.

Before `1.0.0`, breaking changes are possible but should be documented in the
changelog and release notes with migration guidance where applicable. After
`1.0.0`, supported public APIs should not change incompatibly outside a major
release, except for narrowly scoped security corrections with an explicit
notice. No older-version security maintenance period is promised until the
project has the capacity to sustain it.

## Release checklist

For each release, maintainers should:

1. Confirm the [MIT License](LICENSE) is included in the release and the
   copyright-holder placeholder in its notice has been replaced with the
   maintainer-confirmed rights holder name.
2. Update `CHANGELOG.md` with user-visible changes, compatibility impact, and
   upgrade notes.
3. Run Composer validation, the full test suite, PHP syntax checks, and any
   environment-dependent integration checks relevant to the release.
4. Report skipped, blocked, or unverified checks clearly; do not imply that
   skipped database/deployment checks passed.
5. Confirm README, API reference, architecture docs, and implementation-status
   claims match the code.
6. Have an authorized maintainer create a protected version tag and publish
   concise GitHub Release notes from the official repository with the
   PHP/database matrix, known limitations, and security-relevant changes.

Security releases should minimize unrelated changes and provide coordinated
disclosure where a vulnerability was reported privately. See [SECURITY.md](SECURITY.md).
