# Security Policy

Nora is early-stage and has not been independently security-reviewed or
certified. Application owners must review their code, configuration, and
deployment before production use.

## Supported versions

There are no published Nora releases or maintained release branches yet. The
default branch is the current development line. A version support window will
be announced with the first stable release.

## Reporting a vulnerability

**Do not publish vulnerability details, proof-of-concept code, or exploit
instructions in a public issue or discussion.**

If GitHub's private vulnerability reporting is enabled for the repository,
use the repository's **Security → Report a vulnerability** flow. This is the
preferred private channel. Before a public launch, the maintainer must verify
that this feature is enabled and monitored.

Private vulnerability reporting has not been verified as enabled, and no
public security email or alternate monitored private contact has been
configured in this repository. Do not assume a private report can currently
be delivered or monitored. Before inviting broad public security reports, the
maintainer must enable and test GitHub's private reporting feature or publish
a dedicated monitored contact. If neither route is available, do not post
technical details publicly; contact Morave Technologies through a separately
verified private channel and ask for a secure reporting route.

Please include the affected commit or version, impact, relevant environment,
and a minimal reproduction that does not expose real user data. The maintainer
will acknowledge a report when received, investigate privately, coordinate a
fix and disclosure timeline with the reporter, and publish an advisory after
a fix is available where appropriate. Do not assume a report was received
until acknowledged.

## Current limitations

Nora has no independent security review, complete account provider, rate
limiting, upload handling, production deployment recipes, or AI tool execution.
Database migrations have not been integration-tested against a live
MySQL/MariaDB server. Reactive action authorization is deny-by-default when a
callback is omitted, but application callbacks and component rendering are
trusted PHP code; public actions must be intentionally declared, and dynamic
HTML must be escaped by the application. Automated tests are not a security
certification. See the
[security architecture](architecture/SECURITY_ARCHITECTURE.md) and
[implementation status](docs/audit/IMPLEMENTATION_STATUS.md).
