# Tutorial: Your First Nora Application

This tutorial runs the generated counter page end to end. It demonstrates
server rendering, PHP-owned state, explicit actions, and the framework-owned
browser runtime. The generated app uses no application-authored JavaScript.

## Requirements

- PHP 8.2 or newer
- Composer 2
- The Nora source checkout

## Create and install the app

From the Nora checkout, run:

```powershell
php bin\nora new hello-nora
cd hello-nora
composer install
copy .env.example .env
```

In a POSIX shell, the equivalent CLI path is `php bin/nora new hello-nora`;
copy the environment example with `cp .env.example .env`.

The generated project points to the local framework checkout using a Composer
path repository. That path is specific to the machine. Before sharing or
deploying the generated project, change it to a released package or a
deliberately managed repository reference. Nora does not yet publish a tagged
package release.

## Inspect the PHP component

The generated `src/routes.php` registers a `CounterPage` and mounts it at `/`.
`src/Reactive/CounterPage.php` extends `Nora\Reactive\Component`. Its
`initialState()` returns server-owned state, `view(State $state)` composes
escaped markup with `UI`, and `actions()` explicitly allowlists the
`increment` transition:

```php
protected function view(State $state): SafeHtml
{
    return UI::fragment([
        UI::element('h2', [], 'Count: ' . $state->get('count')),
        UI::actionButton('increment', 'Increment', ['amount' => 1]),
    ]);
}
```

The generated page uses `UI::each()` for two keyed counter sections. Both read
the same page state, so one authorized action updates both outputs. `UI`
escapes text and attributes, rejects event-handler/style attributes and unsafe
URL schemes, and creates protocol-ready buttons without hand-written HTML.
`State::computed()` derives a render-time value from the immutable state
snapshot. The framework adds its document-level runtime script and the Nora
UI stylesheet automatically; no custom JavaScript or per-component mounting
code is required.

## Run and interact

Start the development server from the generated project:

```powershell
php ..\bin\nora serve
```

Open `http://127.0.0.1:8000/`. Click either counter button: both displayed
counts should advance without a document navigation. Enter text into the
unsubmitted note field and click a button; the field value and activated
control focus should remain. Stop the development server with `Ctrl+C`.

The generated app performs a real same-origin `POST` to Nora. It sends a
session-bound page handle, an action name, validated arguments, a CSRF token,
and an expected revision. It does not send replacement page state. A stale
revision is rejected instead of replaying the action.

## Add another route

Add a distinct route to `src/routes.php` after mounting the reactive page:

```php
$app->route(
    'GET',
    '/health',
    static fn (\Nora\Http\Request $request, array $parameters): \Nora\Http\Response =>
        \Nora\Http\Response::json(['status' => 'ok'])
);
```

Routes are matched in registration order. Register specific routes before
overlapping parameterized routes.

## Verify the browser behavior

The repository's Playwright test creates and installs a clean generated
application, serves it with PHP's development server, and checks that the
official runtime updates both components without navigation while preserving
input, focus, and keyed DOM identity:

```powershell
# Run from the Nora checkout
npm ci
npx playwright install chromium
npm run test:browser
```

The PHP unit and integration suites are run separately with
`composer test -- --display-warnings`.

## Prototype limitations

This tutorial demonstrates one server-owned page state with adjacent
components. Independent nested component state, lifecycle hooks, reactive
forms, validation feedback UI, navigation/history, offline behavior,
automatic retries, uploads, and persistent real-time transport are not
implemented. PHP component render methods produce trusted markup; escape
dynamic values with `TemplateRenderer`. The development server is not for
production. See the [Reactive UI Engine specification](../../architecture/REACTIVE_UI_ENGINE.md)
for the design and security boundaries.
