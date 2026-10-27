# API Reference

This is a concise index of the current public surface, not an exhaustive
generated API manual. Names below refer to the current implementation; verify
the installed version and source before relying on behavior. Nora has no
stable 1.x compatibility guarantee yet.

## Application and HTTP

| API | Purpose |
|---|---|
| `Nora\Core\Application` | Register routes or a 404 fallback; append or prepend global middleware; access bootstrap configuration; handle a `Request`; or run/send the SAPI response. |
| `Nora\Core\ApplicationBootstrap` | Load an application root's environment/configuration, register routes, create the application, and optionally configure a safe error response factory. |
| `Nora\Http\Request` | Represent an HTTP request; create requests from a URI or PHP globals and read JSON input. |
| `Nora\Http\Response` | Construct text or JSON responses and send them through the SAPI. |
| `Nora\Http\HttpException` | Throw an HTTP exception for centralized status mapping. |
| `Nora\Routing\MiddlewareInterface` | Contract for middleware in the application-wide request pipeline. |

Start with the [first application tutorial](tutorials/FIRST_APPLICATION.md).
Routing is registration-order based; route groups and route-specific
middleware are not currently provided. `Application::fallback(callable)` handles
unmatched paths only; method mismatches still return 405. A fallback response
is assigned status 404 by the router.

An application may pass an error response factory to
`ApplicationBootstrap(errorResponseFactory: $factory)`. Nora continues to log
the exception and passes only its HTTP status to the factory, which must return
a `Response`. Treat the factory as trusted application code and render no
exception message or internal details unless debug disclosure is intentional.
The exception handler preserves the original HTTP status even if the factory
returns a response with a different status.

## Configuration and composition

| API | Purpose |
|---|---|
| `Nora\Config\Config` | Layered array configuration, dot-path access, and required/type validation. |
| `Nora\Config\EnvironmentLoader` | Parse simple environment assignments without interpolation or command execution. |
| `Nora\Container\Container` | Explicit service bindings, object instances, lifetimes, and concrete-class construction. |

Treat PHP configuration files as trusted executable code. Do not use
untrusted input as a container service identifier.

## Validation and rendering

| API | Purpose |
|---|---|
| `Nora\Validation\Validator` | Validate nested input using supported built-in rules and custom rules. |
| `Nora\Validation\ValidationRuleInterface` | Contract for custom validation rules. |
| `Nora\View\TemplateRenderer` | Render plain `.nora` templates with escaped interpolation and root-confined includes. |

Template interpolation escapes HTML text, not JavaScript, CSS, or URL-specific
contexts. Validation does not authorize actions or encode output.

## Database

| API | Purpose |
|---|---|
| `Nora\Database\PdoConnection` | Connect through PDO, execute/select statements, and manage transaction callbacks. |
| `Nora\Database\QueryBuilder` | Build parameterized SELECT queries with constrained identifiers/operators. |
| `Nora\Database\MigrationInterface` | Contract implemented by migration files. |
| `Nora\Database\MigrationRunner` | Apply migrations and report migration history/status. |

The builder supports SELECT, not general insert/update/delete composition.
Bound values use prepared statements. Live MySQL/MariaDB operation still needs
environment-backed integration testing; see
[database architecture](../architecture/DATABASE_ARCHITECTURE.md).

## Security integration

| API | Purpose |
|---|---|
| `Nora\Security\SessionManager` | Native PHP session store with secure cookie defaults. |
| `Nora\Security\SessionMiddleware` | Start the session around a request and close it during response unwind. |
| `Nora\Security\SessionAuthenticator` | Integrate application-supplied user lookup with session login/logout. |
| `Nora\Security\Gate` | Evaluate explicit authorization abilities; undefined abilities deny access. |
| `Nora\Security\CsrfTokenManager` and `CsrfMiddleware` | Manage and check session-backed CSRF tokens for unsafe browser requests. |
| `Nora\Security\PasswordHasher` | Wrap PHP password hashing and verification APIs. |

These are integration building blocks, not a full account provider, rate
limiter, or application authorization policy. Read the
[security policy](../SECURITY.md) before reporting a vulnerability.

## Reactive UI (experimental prototype; API is unstable)

| API | Purpose |
|---|---|
| `Nora\Reactive\ReactiveComponent` | Supply initial page state, server rendering, and an allowlisted action map. |
| `Nora\Reactive\ReactiveAction` | Declare argument rules, a state transition, and an optional authorization callback; missing authorization denies by default. Use `ReactiveAction::publicAction(...)` to intentionally declare an action public to a valid page holder. |
| `Nora\Reactive\ReactiveEngine` | Register page components, install session/action/runtime routes, and mount a server-rendered page with an optional escaped document title. |

The browser runtime is served from `/_nora/runtime.js` and automatically
included in mounted documents. A document has one mounted page component and
one session-owned page state; markup partials are not independent reactive
components. The client submits action intent, not state. `ReactiveAction`
authorization callbacks are optional in the constructor, but an omitted
callback denies by default. Application authors must provide an authorization
callback for protected actions; intentionally public actions use
`publicAction()`. Callbacks must be trusted application code and should be
side-effect free. This experimental API has no stable compatibility
guarantee. Browser-verified behavior and limitations are recorded in the
[reactive engine specification](../architecture/REACTIVE_UI_ENGINE.md).

The generated application's route registration shows the current mounting
shape:

```php
$engine = new ReactiveEngine(new SessionManager(
    $app->config()->get('app.environment') === 'production'
));
$engine->register('counter', new CounterPage(dirname(__DIR__)));
$engine->mount($app, 'counter', '/', 'Counter demo');
```

The fourth `mount()` argument is an optional document title; it defaults to
`Nora` and is HTML-escaped by the framework.

`ReactiveComponent` supplies `initialState(): array`, `render(array $state):
string`, and `actions(): array`. Each `ReactiveAction` receives argument
validation rules, a transition callback, and an optional authorization
callback. When the authorization callback is omitted, the action is denied.
For intentionally public actions, use
`ReactiveAction::publicAction($rules, $transition)`. The authorization
callback receives current state and validated arguments and must return
`true` exactly to allow execution; keep it side-effect free. The transition
receives current state and validated arguments and must return the next state
array. Component markup is trusted PHP output; escape untrusted dynamic values
before returning it.

## CLI and extension contracts

`bin/nora` dispatches the commands documented in
[CLI architecture](../architecture/CLI_ARCHITECTURE.md). Extension contracts
also include `LoggerInterface`, `SessionStoreInterface`,
`AuthenticatorInterface`, `AuthenticatableInterface`, and
`ValidationRuleInterface`.

For exact signatures and current edge cases, the PHP source and tests are the
authoritative reference. A generated API site is not currently published.
