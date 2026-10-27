# Composer and Packagist Release Checklist

## Current package status

The package metadata uses `morave/nora`, derived from the existing
`composer.json` name and the Morave Technologies project identity. This is the
proposed Composer package name, not a claim that the Packagist vendor is
registered, controlled by the maintainer, or available for claiming. On
2026-10-27, `https://repo.packagist.org/p2/morave/nora.json` returned HTTP 404;
that confirms there is no package record at that endpoint at the time checked,
not that the name is reserved or guaranteed to be available.

Nora Framework's code is distributed under the [MIT License](../LICENSE), and
`composer.json` declares `"license": "MIT"`. The root license notice names
Morave Technologies, as confirmed by the maintainer. The MIT license covers
the framework code; it does not itself grant permission to use Nora's name,
logos, or branding to imply an official or endorsed release. See
[TRADEMARKS.md](../TRADEMARKS.md). The website example and VS Code extension
have separate license declarations that require separate scope review.

No Git tags currently exist. There is no release version to install or claim
as published. The package has not been submitted to Packagist.

## Installation modes

### Source checkout, for authorized development/testing

This adds the repository as a VCS source and asks Composer for the development
branch under the terms of the [MIT License](../LICENSE):

```powershell
composer config repositories.nora vcs https://github.com/Yasser-devb/nora.git
composer require morave/nora:dev-main
```

This is not a stable release and follows the moving `main` branch.

### Packagist, after an approved public release

After the publisher identity and package name are verified and a stable tag
is pushed:

```powershell
composer require morave/nora
```

Do not advertise this command as working until the package page and a clean
consumer install have both been verified.

## Prepare a release

1. Review the MIT license and third-party materials; confirm the root
   [`LICENSE`](../LICENSE) contains the maintainer-confirmed notice
   `Copyright (c) 2026 Morave Technologies`. The website example and VS Code
   extension use separate license declarations and must not be assumed to
   inherit the root license.
2. Verify that `morave/nora` can be claimed on Packagist and that the
   authorized maintainer controls the corresponding Packagist account. If it
   cannot be claimed, the owner must choose and verify another vendor name
   before changing package metadata, generated-project manifests, or docs.
3. Review the current worktree, tests, security advisories, supported PHP
   range, package archive contents, documentation, and release notes. Do not
   release while required checks fail or are unverified without an explicit
   release decision.
4. Choose a new SemVer version after reviewing project maturity and existing
   releases. There are currently no tags, but that does not make any
   particular version automatically appropriate. Obtain maintainer approval
   before tagging.
5. Commit and push the reviewed release contents through the authorized
   maintainer workflow. Create an annotated tag only after selecting the
   approved version:

   ```powershell
   $Version = 'REPLACE_WITH_OWNER_APPROVED_VERSION'
   git tag -a $Version -m "Nora Framework $Version"
   git push origin $Version
   ```

6. Create the corresponding GitHub Release from that tag with factual release
   notes. Verify the source archive contains `composer.json`, `src/`, `bin/`,
   required runtime assets, `LICENSE`, and user documentation, but
   excludes tests, local secrets, caches, and development dependencies.
7. Submit the official repository URL through the Packagist website while
   authenticated as the authorized maintainer. Enable the Packagist GitHub
   Service/integration or webhook for future update notifications. Do not put
   Packagist or GitHub credentials in this repository.
8. Verify the package page shows the expected repository and version. In a
   fresh temporary project with the supported PHP extensions, run:

   ```powershell
   composer require morave/nora
   composer show morave/nora
   php -r "require 'vendor/autoload.php'; var_export(class_exists('Nora\\Core\\ApplicationBootstrap'));"
   ```

   Verify `true`, then exercise a minimal application bootstrap and the
   packaged `vendor/bin/nora` command. Report actual results; a successful
   GitHub push or webhook alone does not establish that Packagist installation
   works.

## Package metadata and archive

The library uses PSR-4 autoloading from `Nora\\` to `src/`, with test-only
autoloading under `autoload-dev`. Runtime PHP and extension requirements are
kept explicit. Archive exclusions omit development tests, examples, browser
tooling, local environment files, and generated dependency/cache directories
while retaining `src/`, the CLI, runtime assets, README, and project
documentation.

The current `bin/nora` entry point supports both the repository checkout
autoload path and the Composer-installed project autoloader. Generated
projects still use a path repository to the framework installation that
created them; those generated manifests are machine-local until changed to a
released package or another deliberately managed repository source.
