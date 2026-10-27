# Architectural Decision Records

## ADR-001: Start with a modular monolith

- **Status:** Accepted
- **Context:** The repository was empty and the initial product requirements
  call for a general-purpose PHP framework.
- **Options:** Begin with a modular monolith; split the runtime into services;
  copy an existing framework structure wholesale.
- **Decision:** Build Nora as an independently implemented modular monolith
  with explicit boundaries. The initial core is split into `Core`, `Http`, and
  `Routing` namespaces.
- **Consequences:** The request path stays understandable and testable without
  distributed-system overhead. Module boundaries and contracts must be
  maintained as the framework grows.

## ADR-002: Keep the initial runtime dependency-light

- **Status:** Accepted
- **Context:** There was no existing dependency policy or code to preserve.
- **Options:** Add framework dependencies immediately; start with PHP
  interfaces and add compatible libraries when a concrete need is established.
- **Decision:** The initial runtime requires PHP 8.2+ and no third-party
  production package. PHPUnit is a development dependency.
- **Consequences:** The first core can be bootstrapped with Composer alone, but
  future PSR interoperability and production integrations need deliberate
  dependency decisions.

## ADR-003: Keep route handlers explicit

- **Status:** Accepted
- **Context:** Initial routing needs to be usable without a container or hidden
  global application state.
- **Options:** Resolve arbitrary class names from route strings; inject
  callables explicitly; use a global service locator.
- **Decision:** Routes register callables and receive a request plus named route
  parameters. No user-controlled class resolution or global locator exists.
- **Consequences:** Controller dispatch and DI integration remain future work;
  the initial surface is explicit and straightforward to test.

## ADR-004: Keep configuration loading explicit and production-safe

- **Status:** Accepted
- **Context:** The bootstrap needs environment-specific settings without
  committing credentials or making development error details the default.
- **Options:** Implicitly load any configuration file; load a constrained
  `.env` syntax and known PHP config layers; require a third-party dotenv
  package.
- **Decision:** `ApplicationBootstrap` loads `.env`, `config/app.php`,
  `config/{APP_ENV}.php`, and process values in documented precedence order.
  Production and debug-off are defaults. `.env` has no interpolation or code
  execution.
- **Consequences:** Projects have an explicit boot path and no runtime dotenv
  dependency. PHP config files are trusted executable application code.

## ADR-005: Use plain-text escaped templates

- **Status:** Accepted
- **Context:** A first template system must avoid executing untrusted template
  content and must escape dynamic HTML values by default.
- **Options:** Evaluate PHP templates, add a third-party template engine, or
  implement a limited plain-text interpolation format.
- **Decision:** `.nora` templates support escaped `{{ path }}` variables and
  root-confined `{% include "name" %}` directives. PHP templates and raw
  interpolation are not supported.
- **Consequences:** The initial renderer is small and safe for HTML text
  contexts; it does not distinguish JavaScript, CSS, or URL contexts and
  applications must not use it as a context-aware template engine.

## ADR-006: Keep the MySQL migration limitation explicit

- **Status:** Accepted
- **Context:** MySQL can implicitly commit DDL, making a wrapper transaction
  insufficient for atomic schema changes.
- **Options:** Claim transactional migrations; use an external migration
  package; report migration ledger behavior and operational caveats.
- **Decision:** Nora provides a small migration interface and history table,
  but does not claim MySQL DDL atomicity. A live server is required for
  operational validation.
- **Consequences:** A failed DDL migration may need manual repair before
  rerunning; operators must verify schema and migration ledger together.

## ADR-007: Make project origin explicit without claiming priority

- **Status:** Accepted
- **Context:** Nora's long-term mission is a Moroccan-origin public PHP
  framework, and ecosystem searches cannot establish that it is first or
  unique.
- **Decision:** Identify Morave Technologies as the project's origin and
  maintainer, describe Nora's code-based independence from Laravel and
  Symfony, and avoid unsupported historical or comparative superlatives.
  Keep the comparison dated and scoped.
- **Consequences:** Project identity is clear while claims remain evidence
  based; comparisons must be revisited rather than treated as exhaustive.

## ADR-008: License Nora Framework code under MIT

- **Status:** Accepted (2026-10-27)
- **Context:** Public reuse and third-party contributions require clear terms.
- **Decision:** Nora Framework code is distributed under the MIT License,
  recorded in the root [`LICENSE`](../LICENSE) and Composer metadata. The
  copyright-holder field remains marked for maintainer confirmation because
  repository metadata does not establish the legal rights holder.
- **Consequences:** MIT governs covered code subject to its terms; it does not
  grant permission to use Nora branding to imply endorsement. Confirm the
  copyright-holder name and review the separate website/extension license
  scope before a stable release. See the
  [positioning and licensing note](../docs/PROJECT_POSITIONING.md).

## ADR-009: Evolve public APIs with explicit version policy

- **Status:** Accepted
- **Context:** Nora is pre-release and its public surface is still developing.
- **Decision:** Follow the pre-1.0 and intended post-1.0 semantic versioning,
  compatibility, changelog, and release-checklist guidance in
  [`RELEASE_POLICY.md`](../RELEASE_POLICY.md).
- **Consequences:** Before 1.0, breaking changes remain possible but must be
  identified; stable guarantees require release readiness and maintenance
  capacity.

## ADR-010: Use one server-authoritative page for the first reactive slice

- **Status:** Accepted for prototype; revisit before stabilizing the API
- **Context:** Nora's product direction requires PHP-authored reactive pages,
  one coordinated browser runtime, and no developer-managed hydration islands.
  The existing framework has SSR-capable templates, sessions, CSRF, validation,
  and middleware, but no reactive protocol.
- **Options:** Add client state and arbitrary method RPC; replace the document
  after each event; independently hydrate components; or establish a
  session-owned page state with explicit action definitions and in-place DOM
  reconciliation.
- **Decision:** Render a full HTML page initially. Mount one registered page
  component in a session, store its state server-side, dispatch only named
  allowlisted actions over same-origin fetch, validate arguments and
  authorization on every action, require CSRF and a revision, then reconcile
  server-rendered content under one document root. Prepend the session
  middleware so it wraps session-dependent middleware already registered.
  Use a single delegated framework runtime, not per-component initialization.
- **Consequences:** Server state is not exposed or trusted from the client;
  actions remain explicit and request-bound. Full page rendering after each
  server action is simple to reason about and avoids full-page navigation,
  but may cost more than partial rendering. A keyed DOM reconciler preserves
  compatible browser nodes, unlike naive full replacement, but is not claimed
  to be a general virtual DOM. Session locking and revisions serialize a
  session's operations; durable side effects still need application
  idempotency/transactions.
- **Island trade-off:** Independent hydration boundaries require users to
  manage mounts and component synchronization. Nora instead owns one delegated
  document runtime and one page state/revision boundary. Components remain
  reusable markup with keys; the first slice does not yet provide independent
  child state or fine-grained subscriptions.
- **DOM update trade-off:** Replacing the complete document or page root after
  each event is simpler but loses focus, input state, and DOM identity. The
  prototype reconciles compatible keyed nodes in place. This intentionally
  avoids claiming a full virtual DOM; keyed list semantics and broad
  reconciliation edge cases remain unproven.
- **Not solved by this decision:** independent child state and ownership,
  cross-page synchronization, offline/retry semantics, reactive forms, and
  real-time transports remain future design work.
