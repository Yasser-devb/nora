# Extensibility

## Implemented extension points

- Middleware implements `Nora\Routing\MiddlewareInterface`.
- Service construction uses explicit `Container::bind()`, `singleton()`, and
  `instance()` registrations.
- Logging implements `Nora\Logging\LoggerInterface`.
- Session persistence can implement `Nora\Security\SessionStoreInterface`.
- Authentication integration implements `AuthenticatorInterface` and
  `AuthenticatableInterface`; `SessionAuthenticator` accepts an application
  user resolver.
- Custom validation behavior implements `ValidationRuleInterface`.
- Database migrations implement `MigrationInterface`.
- Reactive pages implement `Nora\Reactive\ReactiveComponent`. Named actions
  use `Nora\Reactive\ReactiveAction` definitions with validation schemas,
  explicit state transitions, and per-action authorization that denies by
  default when no policy is supplied; public actions opt in explicitly.
- Route handlers are explicit callables; controller class dispatch is not
  currently built in.

Implementations should honor these contracts and keep dependencies injected.
Provider-specific AI extensions are not yet supported; they must remain
optional and least-privilege when designed.

Reactive components are opt-in and mounted as pages through
`Nora\Reactive\ReactiveEngine`. One framework-provided runtime delegates events
for the document; developers do not register or mount individual islands.
Initial page SSR and action HTML updates use one session-owned page state.
The early public contract does not yet support independently stateful nested
components, form binding, navigation, or alternate transports. Treat it as a
prototype API until those behaviors and compatibility guarantees are tested.
