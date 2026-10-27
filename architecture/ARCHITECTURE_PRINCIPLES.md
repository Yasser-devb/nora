# Architecture Principles

## Current decisions

- **Independent project:** Nora's core and public APIs are implemented in this
  repository rather than delegated to Laravel or Symfony. Composer and
  established PHP standards remain available interoperability choices.
- **Modular monolith:** Core, HTTP, routing, configuration, security, database,
  presentation, logging, validation, and console code have separate namespaces.
- **Explicit orchestration:** `Application` dispatches requests and invokes the
  middleware pipeline. Business logic belongs in caller-provided handlers.
- **Dependency inversion:** Components depend on small contracts where
  substitution is useful, including middleware, logging, session, and
  authentication contracts.
- **Explicit construction:** Services are registered or constructor-injected;
  there is no ambient global application/service locator.
- **Fail closed:** Undefined Gate abilities deny access; invalid config, route
  handlers, migration files, and templates raise explicit errors.
- **Security at boundaries:** PDO binds values, the view renderer escapes
  interpolation, session cookies use secure defaults, and production
  exceptions return generic responses.
- **No speculative runtime dependencies:** PHPUnit is the only required
  development package. The framework core has no runtime Composer dependency.
- **Sustainable evolution:** Prefer proven behavior, stable contracts, tests,
  and documented compatibility decisions over adding features for count.
  Optional AI integration remains outside the required core.
- **Document-level reactivity:** The reactive prototype uses one delegated
  browser runtime for a server-rendered page, not developer-managed,
  independently hydrated islands. Component state is coordinated by the
  mounted page boundary in the initial slice.
- **Server authority:** Reactive state, action allowlists, validation, and
  authorization remain on the server. The browser sends intent and an
  expected revision, not trusted application state.

## Dependency direction

HTTP types are low-level and independent. Routing depends on HTTP. The
application kernel orchestrates HTTP and routing. Database, security, and view
components are called by application handlers/middleware, not by the router.
AI integrations, when implemented, remain optional.

These are current project constraints, not proof of complete SOLID compliance.
