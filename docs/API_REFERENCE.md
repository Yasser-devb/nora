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

## File uploads and storage

| API | Purpose |
|---|---|
| `Nora\Http\UploadedFile` | Validate PHP upload metadata and expose content-derived MIME/extension information without using the client filename as a path. |
| `Nora\Http\UploadPolicy` and `ValidatedUpload` | Enforce per-file and aggregate file-size limits and an extension-to-MIME allowlist. |
| `Nora\Http\Request::file()` / `files()` | Read validated single or multiple uploads from the PHP-parsed multipart request bag. |
| `Nora\Storage\PrivateFileStorageInterface` | Contract for storing, opening, and deleting private file objects. |
| `Nora\Storage\LocalPrivateFileStorage` and `StoredFile` | Store privately under random identifiers on a local filesystem. |
| `Nora\Storage\AuthorizedPrivateFileReader` | Check an application-provided authorization policy before opening a private stored file. |
| `Nora\Storage\PrivateUploadService` | Validate before storage and clean up earlier writes if a batch fails. |

Example in a route handler:

```php
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Http\UploadPolicy;
use Nora\Storage\AuthorizedPrivateFileReader;
use Nora\Storage\LocalPrivateFileStorage;
use Nora\Storage\PrivateUploadService;

$storage = new LocalPrivateFileStorage(dirname(__DIR__) . '/var/private-uploads');
$policy = new UploadPolicy(
    maximumFileBytes: 2_000_000,
    maximumRequestBytes: 4_000_000,
    allowedExtensionsAndMimeTypes: ['txt' => ['text/plain']],
);
$uploads = new PrivateUploadService($policy, $storage);
$application->route('POST', '/documents', function (Request $request) use ($uploads) {
    $upload = $request->file('document');
    if ($upload === null) {
        return Response::text('A document is required.', 400);
    }

    $stored = $uploads->store($upload);
    // Persist the opaque ID, MIME type, size, and ownership in application storage.
    return Response::json(['id' => $stored->identifier()], 201);
});

// $fileAuthorizer implements PrivateFileAuthorizerInterface; $repository is application-owned.
$reader = new AuthorizedPrivateFileReader($storage, $fileAuthorizer);
$application->route('GET', '/documents/{id}', function (Request $request, array $parameters) use ($reader, $repository) {
    $stored = $repository->findStoredFile($parameters['id']); // Trusted application metadata, never a path.
    if ($stored === null) {
        return Response::text('Not found.', 404);
    }

    $stream = $reader->open($request, $stored); // Denial returns a generic 404 before storage is opened.
    try {
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the private file.');
        }
    } finally {
        fclose($stream);
    }

    return new Response($contents, 200, [
        'Content-Type' => $stored->mimeType(),
        'X-Content-Type-Options' => 'nosniff',
    ]);
});
$application->run(Request::fromGlobals(maxBodyBytes: 4_000_000));
```

`PrivateUploadService::storeBatch($request->files('documents'))` validates the
entire set before writing; if a later write fails, it removes files already
written by that batch. A cleanup failure is logged and surfaced as a storage
failure. `Request::file()` accepts a single file; `Request::files()`
accepts PHP's multiple-file field shape. PHP upload errors are surfaced as HTTP
exceptions (413 for PHP's size errors); `UploadPolicy` enforces content-derived
MIME, extension allowlists, and per-file / aggregate file-byte limits.
`validateBatch()` excludes multipart framing. `Application::run()` uses the
default 1 MiB request cap unless the caller constructs a `Request` with an
explicit `maxBodyBytes` and passes it to `run()`. Set that maximum to cover the
intended multipart body; declared `Content-Length` is checked before accepting
PHP's already-parsed `$_FILES` entries. PHP's
`upload_max_filesize`, `post_max_size`, and an upstream request-body cap must
also be configured. PHP may reject oversized uploads before Nora runs. The
PHP `fileinfo` extension is required at runtime, and client-provided MIME
values are ignored. The upload temporary file's device, inode, and size are
rechecked when copying to storage.

Configure `LocalPrivateFileStorage` with a dedicated directory outside
`DOCUMENT_ROOT`. If PHP exposes `$_SERVER['DOCUMENT_ROOT']`, the adapter
rejects a resolved storage directory inside it. The adapter stores random
128-bit hexadecimal identifiers, never client filenames. On Unix-like systems
it requests owner-only directory/file permissions; Windows deployments must
configure ACLs. Store file metadata and ownership in application persistence;
the filesystem adapter does not provide a retrieval index.
`AuthorizedPrivateFileReader` accepts a trusted `StoredFile` resolved by the
application and calls `PrivateFileAuthorizerInterface::canRead()` before
opening storage. Nora's current `Response` buffers a string, so use an
application/web-server streaming integration for large downloads rather than
buffering large files through this example. There is no public URL, built-in
quota, retention scheduler, malware scanner, or cloud adapter. `delete()`
removes one validated identifier only.

## Database

| API | Purpose |
|---|---|
| `Nora\Database\PdoConnection` | Connect through PDO, execute/select statements, and manage transaction callbacks. |
| `Nora\Database\QueryBuilder` | Build parameterized SELECT, INSERT, UPDATE, and DELETE queries with constrained identifiers/operators. |
| `Nora\Database\QueryDiagnostics` | Optionally log slow-query duration and aggregate metadata without SQL or bound values. |
| `Nora\Database\MigrationInterface` | Contract implemented by migration files. |
| `Nora\Database\MigrationRunner` | Apply migrations and report migration history/status. |

The builder has `select()`, `insert()`, `update()`, and `delete()` factories.
UPDATE and DELETE require at least one `where()` condition; all values are
bound in deterministic order (INSERT/UPDATE values first, then predicates).
`PdoConnection::select()` executes SELECT builders and
`PdoConnection::executeBuilder()` executes mutation builders, returning PDO's
driver-dependent affected-row count. Bound values use prepared statements.
Live MySQL/MariaDB operation still needs environment-backed integration testing; see
[database architecture](../architecture/DATABASE_ARCHITECTURE.md).

Slow-query diagnostics are disabled by default. To enable them, construct
`QueryDiagnostics` with a logger and a nanosecond threshold and pass it as the
third `PdoConnection` argument:

```php
$diagnostics = new QueryDiagnostics($logger, slowQueryThresholdNanoseconds: 50_000_000);
$connection = new PdoConnection($pdo, $logger, $diagnostics);
```

The default timer uses `hrtime(true)`. Slow-query events include the
allowlisted operation label, SQL SHA-256, SQL length, and elapsed nanoseconds;
they never include SQL text or bound values. Applications should choose
thresholds and logger retention appropriate to their privacy and performance
requirements.

## Security integration

| API | Purpose |
|---|---|
| `Nora\Security\SessionManager` | Native PHP session store with secure cookie defaults. |
| `Nora\Security\SessionMiddleware` | Start the session around a request and close it during response unwind. |
| `Nora\Security\SessionAuthenticator` | Integrate application-supplied user lookup with session login/logout. |
| `Nora\Security\Gate` | Evaluate explicit authorization abilities; undefined abilities deny access. |
| `Nora\Security\CsrfTokenManager` and `CsrfMiddleware` | Manage and check session-backed CSRF tokens for unsafe browser requests. |
| `Nora\Security\PasswordHasher` | Wrap PHP password hashing and verification APIs. |
| `Nora\Security\RateLimitStoreInterface` | Atomic storage contract for hashed rate-limit buckets and expired-bucket cleanup. |
| `Nora\Security\FileRateLimitStore` | Single-host file-backed rate-limit storage using locking and atomic updates. |
| `Nora\Security\RateLimiter` and `RateLimitResult` | Enforce a fixed request limit/window and describe allowance, remaining count, and reset/retry timing. |
| `Nora\Security\RateLimitMiddleware` | Opt-in middleware that returns 429 when a bucket is exhausted and 503 when storage fails. |

These are integration building blocks, not a full account provider or
application authorization policy. The file limiter is not automatically
enabled. Read the
[security policy](../SECURITY.md) before reporting a vulnerability.

### Rate limiting

The middleware follows Nora's application-wide pipeline. Its resolver returns
a bounded application-controlled key for requests to limit, or `null` to pass
other requests through. This supports opt-in path selection without adding
route-specific middleware to the router:

```php
use Nora\Http\Request;
use Nora\Security\FileRateLimitStore;
use Nora\Security\RateLimitMiddleware;
use Nora\Security\RateLimiter;

$store = new FileRateLimitStore(dirname(__DIR__) . '/var/rate-limits');
$limiter = new RateLimiter($store, limit: 10, windowSeconds: 60);

$app->middleware(new RateLimitMiddleware(
    $limiter,
    static function (Request $request): ?string {
        return $request->path() === '/login' ? 'route:login' : null;
    },
));
```

Use a stable route/account key that your application controls. Keys must be
1–128 ASCII letters, digits, dots, underscores, colons, or hyphens; Nora hashes
them with SHA-256 before storage. Do not use forwarded IP headers as identity
unless a separately configured, trusted proxy layer has validated them. Nora
does not infer client IP keys.

The response includes `RateLimit-Limit`, `RateLimit-Remaining`, and
`RateLimit-Reset` (seconds until the fixed window ends). Exhausted requests
receive 429 with `Retry-After` in seconds. A store failure fails closed with a
generic 503; there is no implicit fail-open option.

The storage directory contains hashed bucket state and a persistent lock file.
On Unix-like systems Nora requests owner-only directory/file permissions; on
Windows, protect the directory with filesystem ACLs. Use a dedicated directory
outside the public document root. Call `$store->cleanup(time())` from a
maintenance task to remove expired state files; it only removes correctly
named bucket files whose recorded window has expired. File storage is for one
host, or a shared filesystem only after its `flock` semantics have been
verified. It is not a distributed limiter. Application rate limiting does not
replace reverse-proxy, web-server, network, or resource-level DoS controls.

## Reactive UI (experimental prototype; API is unstable)

| API | Purpose |
|---|---|
| `Nora\Reactive\ReactiveComponent` | Supply initial page state, server rendering, and an allowlisted action map. |
| `Nora\Reactive\ReactiveParentComponentInterface` / `ReactiveChildDefinition` | Declare stable keyed nested children and their validated props. |
| `Nora\Reactive\ReactiveNestedComponentInterface` | Define child props rules, independent state, rendering, actions, and recursive children. |
| `Nora\Reactive\ReactiveComponentLifecycleInterface` | Receive mount and disposal callbacks for nested child instances. |
| `Nora\Reactive\ReactiveAction` | Declare argument rules, a state transition, and an optional authorization callback; missing authorization denies by default. Use `ReactiveAction::publicAction(...)` to intentionally declare an action public to a valid page holder. |
| `Nora\Reactive\ReactiveEngine` | Register page components, install session/action/runtime routes, and mount a server-rendered page with an optional escaped document title. |

The browser runtime is served from `/_nora/runtime.js` and automatically
included in mounted documents. A document has one mounted page component;
`ReactiveParentComponentInterface` may declare independent nested child
instances. Each child has a stable key within its parent, validated props,
server-issued random identity, isolated session state and its own action map.
Use `ReactiveChildDefinition::placeholder($key)` exactly once in the parent
markup for each definition. Child props changes dispose and remount the
instance; removing a child disposes it and removes its state. Optional
`ReactiveComponentLifecycleInterface` hooks receive the server-issued identity.
The engine bounds a page to 64 child instances and 64 KiB of combined state.
The client submits action intent and a child identity, never child state. The
server resolves the identity only within the session-owned page and checks
authorization, CSRF, and the page revision on every action. `ReactiveAction`
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

Nested child components implement `ReactiveNestedComponentInterface`; their
`propsRules()` are validated by Nora's `Validator` before initialization or
render, and their `initialState(array $props)` result is independent of the
parent and sibling states. A parent implements
`ReactiveParentComponentInterface::children(array $state)` and returns a
deterministically ordered list of `ReactiveChildDefinition` values with
unique stable keys. Child state is preserved when the key, type, and props
remain unchanged. A page-level revision serializes changes across its root and
children, so an action from an old revision is rejected with 409 rather than
replayed. This is not a virtual-DOM framework or a cross-page state store.

### Reactive forms (experimental)

The browser runtime supports opt-in forms using the existing reactive action
endpoint; there is no separate form-builder or server-side form object. Mark a
form with `data-nora-form` and `data-nora-action`. Bind each supported
`input`, `select`, or `textarea` by setting both its `name` and matching
`data-nora-field`. The action's server-side `ReactiveAction` rules are the
allowlist and validation contract:

```html
<form data-nora-form
      data-nora-action="save"
      data-nora-success="Profile saved"
      method="post"
      action="/profile">
  <label>
    Email
    <input name="email" type="email" data-nora-field="email">
  </label>
  <button type="submit">Save</button>
</form>
```

```php
'save' => ReactiveAction::publicAction(
    ['email' => 'required|email'],
    static fn (array $state, array $arguments): array => [
        ...$state,
        'email' => $arguments['email'],
    ],
),
```

Use an explicit authorization callback instead of `publicAction()` when the
action is protected. The runtime submits only bound controls, rejects file
inputs and multiple-select controls, and does not trust browser validity.
Server-side action rules reject unknown arguments. A 422 response creates an
alert summary, associates field messages with controls using
`aria-describedby`, marks invalid fields, and focuses the first invalid field.
Messages are inserted as text, not markup. While a request is in flight,
`data-nora-state` is `pending` and `aria-busy` is set; terminal states are
`invalid`, `success`, or `failed`. Compatible control values and focus are
preserved through successful DOM reconciliation. Do not include passwords,
tokens, or other secrets in rendered state or action responses.

The form still has ordinary `method` and `action` attributes. If JavaScript is
disabled, the browser submits a normal POST to that application route; the
application must implement that route and repeat validation, authorization,
and CSRF protection itself. Nora does not automatically implement a no-JavaScript
form handler. This convention is experimental and browser-tested locally in
Chromium; it is not a cross-browser accessibility certification.

## CLI and extension contracts

`bin/nora` dispatches the commands documented in
[CLI architecture](../architecture/CLI_ARCHITECTURE.md). Extension contracts
also include `LoggerInterface`, `SessionStoreInterface`,
`AuthenticatorInterface`, `AuthenticatableInterface`, and
`ValidationRuleInterface`.

For exact signatures and current edge cases, the PHP source and tests are the
authoritative reference. A generated API site is not currently published.
