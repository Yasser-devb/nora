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

## Reactive UI (experimental; API is unstable)

| API | Purpose |
|---|---|
| `Nora\Reactive\ReactiveComponent` | Supply initial page state, server rendering, and an allowlisted action map. |
| `Nora\Reactive\ReactiveParentComponentInterface` / `ReactiveChildDefinition` | Declare stable keyed nested children and their validated props. |
| `Nora\Reactive\ReactiveNestedComponentInterface` | Define child props rules, independent state, rendering, actions, and recursive children. |
| `Nora\Reactive\ReactiveComponentLifecycleInterface` | Receive mount and disposal callbacks for nested child instances. |
| `Nora\Reactive\ReactiveAction` | Declare argument rules, a state transition, and an optional authorization callback; missing authorization denies by default. Use `ReactiveAction::publicAction(...)` to intentionally declare an action public to a valid page holder. |
| `Nora\Reactive\ReactiveEngine` | Register page components, install session/action/runtime routes, and mount a server-rendered page with an optional escaped document title. |
| `Nora\Reactive\Component` | Implement the existing page-component contract with a safe `view(State): SafeHtml` method while keeping state transitions explicit. |
| `Nora\Reactive\State` | Read a render-time state snapshot with dot-path lookup and compute derived values without mutating server state. |
| `Nora\Reactive\UI` / `SafeHtml` | Compose escaped text, HTML elements, fragments, conditions, keyed collections, and action buttons. |
| `Nora\Reactive\Props` / `Slots` | Validate declared prop types/defaults and pass safe markup through named/default slots. |
| `Nora\Reactive\Components\Button`, `Alert`, `Card`, `Field`, `ReactiveForm`, `Table`, `Layout`, `Modal`, `Tabs`, `Dropdown` | Render Nora UI kit primitives. Modal, tabs, and dropdown use the framework runtime for their documented interactions. |

The browser runtime is served from `/_nora/runtime.js` and automatically
included in mounted documents. A document has one mounted page component;
`ReactiveParentComponentInterface` may declare independent nested child
instances. Each child has a stable key within its parent, validated props,
server-issued random identity, isolated session state and its own action map.
Use `ReactiveChildDefinition::placeholder($key)` exactly once in the parent
markup for each definition. Child props changes dispose and remount the
instance; removing a child disposes it and removes its state. Optional
`ReactiveComponentLifecycleInterface` hooks receive the server-issued identity.
The engine bounds a page to 64 child instances, 32 nested component levels,
262,144 bytes of rendered HTML, and 64 KiB of combined state.
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

### Composable PHP UI API

`Component` is an optional base class over the existing `ReactiveComponent`
contract. It does not create another engine, infer browser-callable methods, or
change the registration/action protocol. Components still implement
`initialState()` and an explicit `actions()` map. Override the protected view
method with a typed `State` snapshot and return `SafeHtml` built by `UI`:

```php
use Nora\Reactive\Component;
use Nora\Reactive\ReactiveAction;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\State;
use Nora\Reactive\UI;

final class Counter extends Component
{
    public function initialState(): array
    {
        return ['count' => 0];
    }

    protected function view(State $state): SafeHtml
    {
        $count = $state->get('count');
        $nextCount = $state->computed(
            static fn (State $snapshot): int => $snapshot->get('count') + 1,
        );

        return UI::fragment([
            UI::element('output', [], $count),
            UI::actionButton('increment', 'Increment', ['amount' => 1]),
            UI::when($nextCount < 10, UI::element('small', [], 'More increments available')),
        ]);
    }

    public function actions(): array
    {
        return [
            'increment' => new ReactiveAction(
                ['amount' => 'required|integer|min:1|max:1'],
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'count' => $state['count'] + $arguments['amount'],
                ],
                static fn (array $state): bool => $state['count'] < 10,
            ),
        ];
    }
}
```

`UI::element()` escapes scalar child text and attribute values. It rejects
event-handler, inline-style, and `srcdoc` attributes; multi-URL attributes and
meta refresh; executable `script` and `style` elements; and URL schemes other
than HTTP, HTTPS, `mailto`, and `tel`.
Boolean HTML attributes are emitted as empty attributes, while ARIA/data
booleans are emitted as `"true"` or `"false"`. Use CSS classes instead of
inline styles. A `SafeHtml` value can be nested as a child without being
escaped a second time. `SafeHtml::trusted()` is an explicit trust boundary:
only pass markup generated by trusted application code.

`UI::fragment()` composes multiple roots. `UI::when()` conditionally includes
one child. `UI::each($items, $key, $render)` calls the key selector with each
item and the renderer with each item/key; keys must be unique strings or
integers, and each renderer must return one `SafeHtml` element. The key is
emitted as `data-nora-key` for the existing runtime reconciler. Keep keys
stable across renders when items are reordered.

`UI::actionButton()` creates a semantic button with the existing
`data-nora-action` protocol attributes. Its action name and string-keyed
arguments are serialized safely, but the registered `actions()` map remains
the only server-side exposure mechanism. Its argument declarations and
authorization callback are still mandatory security decisions. `State` is an
immutable render snapshot: `get()` supports dot paths, `has()` checks
existence, and `computed(callable)` evaluates a derived value for that render.
State mutation remains the responsibility of an authorized
`ReactiveAction` transition.

`Props::resolve($definitions, $values)` checks declared prop names and strict
types (`string`, `integer`, finite `float`/`number`, `boolean`, `array`, or
`safe_html`), applies explicit defaults, rejects unknown values, and raises an
exception for missing required props. No type coercion is performed. A
definition may include `one_of` for a strict enum. `Slots` accepts only
`SafeHtml` values under named keys; use `get('default', $fallback)` for an
optional slot or `require('header')` for mandatory content.

The UI kit includes the page, feedback, form, table, and interaction helpers
listed below. Props are strict (no implicit coercion); undocumented props are
rejected. Content passed as `SafeHtml` or through `Slots` is trusted markup
created by Nora's composition API. Plain string labels, descriptions, values,
and messages are escaped.

### Component catalogue

| Component | Signature and supported options |
|---|---|
| `Button` | `Button::render($props, $label, $attributes = [], ?Slots $slots = null)`. Variants: `primary`, `secondary`, `outline`, `ghost`, `link`, `danger`; sizes: `small`, `medium`, `large`. `disabled` and `loading` default to false; loading disables the button and announces `aria-busy`. `type` defaults to `button`; `action` and string-keyed `arguments` bind only to a registered server action. Optional `leading-icon` and `trailing-icon` slots are decorative. |
| `Alert` | `Alert::render($props, Slots $slots)`. `variant`: `info`, `success`, `warning`, `danger`, or `error`; `label` defaults to `Notice`; `dismissible` defaults to false. Optional `title`, `icon`, and `default` slots. Dismissal is client-only. |
| `Card` | `Card::render($props, Slots $slots)`. `as` defaults to `section` (`article` and `div` are also accepted); optional `labelled_by`, `title`, and `description` props. Slots: `header`, `default`, `actions`, and `footer`. Missing slots render empty. |
| `Field` | `Field::render($props, SafeHtml $control, $errors = [])`. Requires `name` and `label`; optional `required`, `hint`; errors are escaped and associated with the control. Supply a real labelable input, select, or textarea. |
| `ReactiveForm` | `ReactiveForm::render($props, SafeHtml $children, $attributes = [])`. Requires `action_name`; `action_url` defaults to `#`; `success_message` is optional. Bind controls with matching `name` and `data-nora-field`; the action schema remains authoritative. |
| `Select` | `Select::render($props, $options)`. Requires `name` and `label`; optional `value`, `placeholder` (`Choose an option`), `required`, and `disabled`. Each option has a unique non-empty string `value`, non-empty string `label`, and optional boolean `disabled`. A non-empty selected value must match an option. |
| `Checkbox` | `Checkbox::render($props)`. Requires `name` and `label`; optional `hint`, `checked`, `required`, and `disabled` (booleans default false). |
| `RadioGroup` | `RadioGroup::render($props, $options)`. Requires `name`, `label`, and a non-empty options list; optional `value`, `hint`, `required`, and `disabled`. Option values are unique non-empty strings; labels are non-empty strings; option `disabled` is boolean. Outputs a labeled `fieldset` and `legend`. |
| `SwitchControl` | `SwitchControl::render($props)`. Requires `name` and `label`; `checked` and `disabled` default to false. Renders a checkbox with `role="switch"`. |
| `Textarea` | `Textarea::render($props, $errors = [])`. Requires `name` and `label`; `value` defaults to empty text, `rows` to 4 (range 1–30), `required` and `disabled` to false; optional `hint`. Uses `Field` for accessible error bindings. |
| `Table` | `Table::render($props, $headers, $rows, $key, $cells)`. Requires at least one header; optional `caption` and `empty_message` (`No results.`). Produces semantic headers and stable keyed rows; it does not search, sort, or paginate. |
| `DataTable` | `DataTable::render($props, iterable $rows, callable $key, callable $cells)`. Requires `columns` (unique `key` and non-empty `label`) and `caption`; optional `query`, `sort`, `direction` (`asc` or `desc`), `page` (1), `per_page` (5), `sort_action`, `page_action`, `action_arguments`, and `empty_message`. Search and sorting use raw scalar row fields named by the columns; callbacks render the visible cells. Paging happens after in-memory search/sort. Use a database query for large or unbounded datasets rather than materializing them in this helper. |
| `SearchField` | `SearchField::render($props)`. `name` defaults to `query`, `label` to `Search`, `value` to empty text, and `placeholder` to `Search records`. The clear button clears locally; submitting still requires a form/action integration. |
| `Pagination` | `Pagination::render($props, $baseArguments = [])`. Requires integer `current` and `total`; optional `action`, `page_argument` (`page`), and accessible `label` (`Pagination`). With `action`, emits reactive buttons with base arguments; without it, emits ordinary page links. |
| `Modal` | `Modal::render($props, SafeHtml $content)`. Requires a unique safe `id` and non-empty `title`; `trigger` and `close_label` default to `Open dialog` and `Close dialog`; `description` is optional; `open` defaults false; `close_action` optionally synchronizes browser close/Escape events with a registered server action. Uses native `<dialog>`. |
| `Tabs` | `Tabs::render($id, $label, $tabs, $selected = null)`. Each tab needs a unique safe `id`, non-empty `label`, and `SafeHtml` content; optional boolean `disabled`. The first enabled tab is selected by default. Arrow/Home/End keyboard operation skips disabled tabs. |
| `Dropdown` | `Dropdown::render($props, SafeHtml $items)`. Requires non-empty accessible `label`; uses native `<details>/<summary>` and closes on Escape or outside click. Menu content is developer-supplied safe markup. |
| `Accordion` | `Accordion::render($label, $items, $multiple = true)`. Each item has a unique safe `id`, non-empty `title`, `SafeHtml content`, and optional boolean `open`. Uses native `<details>/<summary>`; `multiple: false` groups disclosures by name. |
| `Badge` | `Badge::render($props, string\|SafeHtml $label, ?SafeHtml $icon = null)`. `variant` defaults to `neutral` (`neutral`, `primary`, `success`, `warning`, `danger`, `info`); optional boolean `dot`. |
| `Avatar` | `Avatar::render($props)`. Requires non-empty accessible `label`; optional safe `src` and `initials`; `size` defaults to `medium` (`small`, `medium`, `large`). Failed images fall back to initials. |
| `Spinner` | `Spinner::render($props = [])`. `size` defaults to `medium`; `label` to `Loading`; `announce` to true. Use `announce: false` for an explicitly decorative spinner. |
| `Skeleton` | `Skeleton::render($props = [])`. Decorative `variant` defaults to `text` (`text`, `rectangle`, `avatar`, `card`), `width` to `full` (`full`, `three-quarter`, `half`, `quarter`), and `height` to `medium` (`small`, `medium`, `large`). |
| `Progress` | `Progress::render($props)`. Requires a non-empty accessible `label`; optional `value` in `[0, max]`; `max` defaults to 100 and must be positive. Omitting `value` produces an indeterminate native `<progress>`. |
| `Separator` | `Separator::render($props = [])`. `orientation` defaults to `horizontal` (`horizontal`, `vertical`); `decorative` defaults true. |
| `Tooltip` | `Tooltip::render($props, SafeHtml $trigger)`. Requires safe `id` and non-empty text `content`; trigger must be a single element so Nora can add `aria-describedby`. Provides hover/focus presentation and an ARIA tooltip relationship; it is not a substitute for essential instructions. |
| `Breadcrumb` | `Breadcrumb::render($label, $items)`. Requires a non-empty landmark label and items; every item has a non-empty `label`, all but the last require `href`, and the last is marked `aria-current="page"`. |
| `StatCard` | `StatCard::render($props)`. Requires non-empty `label` and string `value`; optional `description`, `trend` (`up`, `down`, `neutral`), and `trend_label`. Trend text is shown only when both trend props are present. |
| `EmptyState` | `EmptyState::render($props, Slots $slots)`. Requires non-empty `title`; optional `description`; optional `icon` and `action` slots. |

`Layout::render()` composes `navigation`, `header`, `default`, and `footer`
slots into a reusable page shell. `Button`, alerts, tables, and forms remain
usable as standalone server-rendered helpers, but static pages outside the
reactive document shell must include `/_nora/ui.css` themselves.

The mounted runtime delegates actions and the small client-only interactions
above; it does not turn every component method into a browser action. Tab,
dropdown, accordion, and local dismiss/clear behavior do not change server
state. When a modal has `close_action`, close and Escape dispatch only that
explicitly registered action. The runtime preserves focus when a dialog
opens/closes and recovers avatar fallbacks when an image fails before deferred
runtime startup. These semantics have local Chromium regression coverage, not
cross-browser or automated assistive-technology certification.

Mounted reactive documents load `/_nora/ui.css`, which uses `--nora-*`
customization tokens and follows the operating system's light/dark color
scheme. The command palette and a general-purpose animation/transition system
are not included.

Existing classes implementing `ReactiveComponent` directly remain compatible.
Nested component props continue to use `propsRules()` and the existing
validator; the new typed prop/slot helpers do not change that child protocol,
which does not yet resolve PHP class components as reactive children.
`.nora` templates continue to support escaped interpolation and includes, not
conditional or loop syntax.

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
endpoint; `Components\ReactiveForm` and `Components\Field` provide rendering
helpers, but there is no separate server-side form model. Mark a form with
`data-nora-form` and `data-nora-action`. Bind each supported
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

The CLI provides `php bin\nora make:component Alert`, which creates a
`src/Reactive/Alert.php` component using the composable base API. It validates
the class name and refuses to overwrite an existing file.

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
