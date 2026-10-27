# Project Positioning and Comparison

**Review date:** 2026-10-27

## Origin and mission

Nora is created in Morocco by Morave Technologies, its project origin and
maintainer. The goal is to build an independent, high-quality PHP framework
that developers in Morocco and internationally can use, study, and improve.
This statement describes the project's identity; it makes no claim about
historical priority or national recognition.

Nora's core and APIs are implemented in this repository. It does not use
Laravel or Symfony as its underlying framework. The current Composer manifest
requires PHP extensions and PHPUnit for development, but no Laravel or Symfony
runtime package. Nora may use Composer libraries and PHP interoperability
standards where they solve a concrete problem; independence does not mean
avoiding the PHP ecosystem.

## Distinctive direction

The project's engineering direction is to combine:

- a lightweight modular core with explicit HTTP, routing, configuration,
  security, database, validation, logging, console, and view boundaries;
- explicit composition and small contracts rather than ambient global
  service lookup;
- developer-facing CLI tooling and safe defaults, verified with tests;
- transparent separation of implemented, tested, skipped, blocked, and
  planned work; and
- an optional, provider-neutral AI integration boundary with validated tools,
  explicit permissions, and auditability, only after the framework foundation
  is stable.

This is a statement of intended focus, not a claim that Nora currently
outperforms other frameworks. AI integration, full account workflows,
production deployment support, live database integration validation, and
stable post-1.0 API guarantees are not complete.

## Scoped ecosystem review

On 2026-10-27, a limited public GitHub repository search was made for
`Moroccan PHP`, `Morocco PHP`, `Morocco PHP framework`, and
`PHP framework "Morocco"`. These searches surfaced examples of valuable
Moroccan PHP activity, but did not provide a complete census or enough
evidence to assert that no comparable framework exists:

| Project | Public description/evidence observed | Relevance to Nora |
|---|---|---|
| [mehdirochdi/cmi-payment-php](https://github.com/mehdirochdi/cmi-payment-php) | A PHP package for communicating with Morocco's CMI payment platform. | A focused integration library, not a general-purpose framework; illustrates useful ecosystem tooling. |
| [asm-products/moroccan-php-artisans](https://github.com/asm-products/moroccan-php-artisans) | A repository for a group describing its goal as building a PHP community in Morocco. | Community history and ecosystem context, not a framework comparison. |
| [saadel/mphpa-blog-api](https://github.com/saadel/mphpa-blog-api) | A blog API described as built with Laravel 5 for a Moroccan PHP Artisans meetup. | A Moroccan PHP application built on Laravel, not an independently implemented framework. |
| [alaouy/blog-mphpa](https://github.com/alaouy/blog-mphpa) | A Laravel 5 blog associated with the same meetup; the repository is marked archived. | Historical application example, not a framework comparison. |

Searches were discoverability checks, not verification of the owners'
nationality, project provenance, current maintenance, architecture, or license
terms. Search ranking, wording, and GitHub coverage can miss relevant work.
Repository descriptions and metadata are not independent provenance audits.
This review therefore does **not** claim that Nora is the first Moroccan PHP
framework, the only independent one, or the most distinctive one. The list
should be revisited before publishing any comparative or priority claim.

## License

Nora Framework's code is distributed under the [MIT License](../LICENSE), and
`composer.json` declares `"license": "MIT"`. The root license notice names
Morave Technologies, as confirmed by the maintainer. The MIT terms permit use,
modification, and redistribution subject to the license conditions and
disclaimer. This notice does not assert that every third-party contribution
has the same copyright holder; contribution rights remain subject to the
applicable terms.

Copyright, code permissions, repository administration, merge authority,
release authority, and branding are separate matters. A code license grants
only the rights described by its terms; it does not give a contributor
official-repository access, merge authority, or authority to publish an
official Nora release. It does not prevent license-compliant third parties
from creating and publishing modified forks. See
[contribution guidance](../CONTRIBUTING.md),
[release policy](../RELEASE_POLICY.md), and
[proposed name and branding guidance](../TRADEMARKS.md).

Review authorship and third-party materials as part of release preparation.
No contributor copyright assignment or CLA is introduced. See
[trademark guidance](../TRADEMARKS.md) for the separate use of Nora's name and
branding.

## Positioning guardrails

- Say “created in Morocco by Morave Technologies” as project identity; do not
  turn that into an unsupported “first” or “only” claim. The project
  designates `Yasser-devb/nora` as its official repository; do not imply that
  forks or third-party releases are official or endorsed.
- Describe only code-backed current behavior as implemented.
- Keep the required framework core independent of AI providers.
- Compare specific capabilities and evidence, not national origins or
  unverified competitor weaknesses.
- Keep the comparison scope and review date visible, and revisit it as the
  ecosystem and Nora change.
