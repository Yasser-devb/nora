# Components and Implementation Status

| Component | Current implementation | Verification |
|---|---|---|
| Application/bootstrap | Environment/config loading, routes, request lifecycle | Unit, integration, generated app smoke run |
| HTTP | Request from URI/SAPI, form/JSON parsing, response send/json/text | Unit and generated app smoke run |
| Router/middleware | Ordered method/path matching, named parameters, 404/405, pipeline | Unit and integration |
| Container | Explicit bindings, singleton/transient, constructor injection, cycles | Unit |
| Configuration | Layered arrays, dot paths, required/type checks, `.env` parser, bootstrap access | Unit and integration |
| Exceptions/logging | HTTP status mapping, production-safe response, JSON log redaction | Unit and integration |
| Validation | Required, type, format, min/max, nested values, custom rules | Unit |
| Database | PDO wrapper, prepared parameters, SELECT builder, transactions, migrations | Unit doubles; real MySQL blocked |
| CLI | Help/version, safe project scaffold, class generators, migration commands, dev server | Unit; scaffold install/first request smoke-tested |
| Security | Session middleware/cookies, fixation rotation, CSRF invalidation, password APIs, Gate, CSRF | Unit and CSRF integration |
| Views | Plain text `.nora`, escaped values, includes, path/cycle checks | Unit and integration |
| Reactive UI | SSR component pages, explicit actions, session state, CSRF/revisions, delegated browser runtime and keyed DOM updates | PHP integration tests and generated-app Playwright E2E |
| AI integration | None | Planned |
| Deployment | Documentation only | Not validated on Linux/Apache/Nginx |

The table differentiates implementation from environment-dependent verification.
It does not imply production suitability.

The reactive row describes an early vertical slice only. Its state boundary is
one page, with adjacent components sharing page state; independent nested
component ownership, forms, navigation, and persistent transport are planned.
See [Reactive UI Engine](REACTIVE_UI_ENGINE.md).
