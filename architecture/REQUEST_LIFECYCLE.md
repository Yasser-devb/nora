# Request Lifecycle

## Web entry point

```mermaid
sequenceDiagram
    participant SAPI as Web SAPI
    participant Entry as public/index.php
    participant Bootstrap as ApplicationBootstrap
    participant App as Application
    participant Request as Request
    participant Pipeline as MiddlewarePipeline
    participant Router
    participant Handler as Handler
    participant Error as ExceptionHandler
    participant Response

    SAPI->>Entry: Request
    Entry->>Bootstrap: boot(root)
    Bootstrap->>Bootstrap: load .env/config and register route callable
    Entry->>App: run()
    App->>Request: fromGlobals()
    App->>Pipeline: handle(request)
    Pipeline->>Router: dispatch(request)
    Router->>Handler: invoke(request, route parameters)
    Handler-->>Router: Response
    Router-->>Pipeline: Response
    Pipeline-->>App: Response
    App->>Response: send()
    App->>Error: map thrown request exception
```

`Application::handle(Request)` is the non-SAPI test seam. Middleware registration
order is outer-to-inner for request processing and reversed for response
unwinding. A middleware may return a response without calling downstream.
Exceptions thrown by request processing are sent to `ExceptionHandler`.
Exceptions while constructing the bootstrap are surfaced to the caller.

## Middleware execution

```mermaid
flowchart LR
    Request --> First[First registered middleware]
    First --> Second[Second registered middleware]
    Second --> Router[Router and route handler]
    Router --> Response
    Response -. unwind .-> Second
    Second -. unwind .-> First
    First -. may short-circuit .-> Response
```

The current pipeline is global to an `Application` instance; route-specific
middleware and groups are not implemented.

## Reactive action lifecycle (prototype)

```mermaid
sequenceDiagram
    participant Browser
    participant Runtime as Delegated browser runtime
    participant App as Application route
    participant Session as SessionMiddleware
    participant Engine as ReactiveEngine
    participant Action as Explicit ReactiveAction
    participant Component as ReactiveComponent

    Browser->>App: GET mounted page
    App->>Session: start session
    Session->>Engine: render initial page and CSRF token
    Engine->>Component: initial state + render
    Engine-->>Browser: full SSR HTML and runtime asset
    Browser->>Runtime: click data-nora-action control
    Runtime->>App: POST JSON action + args + revision + CSRF
    App->>Session: start same session
    Session->>Engine: dispatch action request
    Engine->>Engine: verify payload, page ownership, CSRF, revision
    Engine->>Action: validate arguments and authorize
    Action-->>Engine: next server state
    Engine->>Component: render current page state
    Engine->>Session: persist state and next revision
    Engine-->>Runtime: JSON HTML update
    Runtime->>Browser: reconcile keyed nodes; retain compatible DOM
```

Reactive routes are opt-in. Installing a `ReactiveEngine` prepends its session
middleware and adds fixed runtime/action routes, ensuring previously
registered session-dependent middleware runs after the session starts. Each
page state is stored in that session; action requests cannot submit replacement
state or choose a PHP method. A revision mismatch returns current HTML with
HTTP 409, without reapplying the action. The first implementation re-renders
the component page after each successful server action; it does not reload the
document.

## Container resolution

```mermaid
sequenceDiagram
    participant Composition as Application composition code
    participant Container
    participant Reflection
    participant Service
    Composition->>Container: bind interface to implementation
    Composition->>Container: get(concrete service)
    Container->>Reflection: inspect constructor
    Reflection-->>Container: typed dependencies
    Container->>Container: detect cycles and resolve dependencies
    Container->>Service: construct with dependencies
    Container-->>Composition: service object
```

The container is independently usable; `ApplicationBootstrap` does not
silently turn it into a global service locator.
