<?php

declare(strict_types=1);

namespace Nora\Reactive;

use JsonException;
use Nora\Core\Application;
use Nora\Http\HttpException;
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Security\CsrfTokenManager;
use Nora\Security\SessionManager;
use Nora\Security\SessionMiddleware;
use RuntimeException;

final class ReactiveEngine
{
    private const PAGE_SESSION_KEY = 'nora.reactive.pages';

    private const ACTION_PATH = '/_nora/action';

    private const RUNTIME_PATH = '/_nora/runtime.js';

    private const MAX_PAGES_PER_SESSION = 32;

    private const PAGE_TTL_SECONDS = 1800;

    private const MAX_REQUEST_BYTES = 16384;

    private const MAX_STATE_BYTES = 65536;

    private const MAX_RENDERED_BYTES = 262144;

    private const MAX_CHILDREN_PER_PAGE = 64;

    /** @var array<string, ReactiveComponent|ReactiveNestedComponentInterface> */
    private array $components = [];

    /** @var array<string, array<string, ReactiveAction>> */
    private array $actions = [];

    private readonly CsrfTokenManager $csrfTokens;

    private bool $installed = false;

    public function __construct(private readonly SessionManager $session)
    {
        $this->csrfTokens = new CsrfTokenManager($session);
    }

    public function register(
        string $name,
        ReactiveComponent|ReactiveNestedComponentInterface $component,
    ): self
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('A reactive component name is invalid.');
        }
        if (array_key_exists($name, $this->components)) {
            throw new \InvalidArgumentException('A reactive component name is already registered.');
        }

        $actions = $component->actions();
        if (!is_array($actions)) {
            throw new \InvalidArgumentException('A reactive component must return an action map.');
        }
        foreach ($actions as $actionName => $action) {
            if (
                !is_string($actionName)
                || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $actionName) !== 1
                || !$action instanceof ReactiveAction
            ) {
                throw new \InvalidArgumentException('Reactive actions must have safe names and explicit definitions.');
            }
        }
        $this->components[$name] = $component;
        $this->actions[$name] = $actions;

        return $this;
    }

    public function mount(
        Application $application,
        string $componentName,
        string $path,
        string $documentTitle = 'Nora',
    ): void
    {
        if (
            !array_key_exists($componentName, $this->components)
            || !$this->components[$componentName] instanceof ReactiveComponent
        ) {
            throw new \InvalidArgumentException('The reactive component is not registered.');
        }
        $this->install($application);

        $application->route(
            'GET',
            $path,
            fn (): Response => $this->createPageResponse($componentName, $documentTitle),
        );
    }

    private function install(Application $application): void
    {
        if ($this->installed) {
            return;
        }

        $application->prependMiddleware(new SessionMiddleware($this->session));
        $application->route('GET', self::RUNTIME_PATH, fn (): Response => $this->runtimeResponse());
        $application->route('POST', self::ACTION_PATH, fn (Request $request): Response => $this->dispatchAction($request));
        $this->installed = true;
    }

    private function createPageResponse(string $componentName, string $documentTitle): Response
    {
        $pages = $this->pages();
        if (count($pages) >= self::MAX_PAGES_PER_SESSION) {
            throw new HttpException(429, 'Too many reactive pages are open in this session.');
        }

        $component = $this->components[$componentName];
        $state = $component->initialState();
        $this->assertSafeState($state);
        $pageId = bin2hex(random_bytes(24));
        $revision = 0;
        $children = [];
        $activeChildren = [];
        $content = $this->renderTree($componentName, $state, [], $children, $activeChildren, '');
        $this->disposeInactiveChildren($children, $activeChildren);
        $this->assertPageLimits($state, $children, $content);
        $pages[$pageId] = [
            'component' => $componentName,
            'state' => $state,
            'children' => $children,
            'revision' => $revision,
            'updated_at' => time(),
        ];
        $this->session->set(self::PAGE_SESSION_KEY, $pages);

        $csrfToken = htmlspecialchars($this->csrfTokens->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $document = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="nora-csrf" content="' . $csrfToken . '"><title>'
            . htmlspecialchars($documentTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</title>'
            . '<script defer src="' . self::RUNTIME_PATH . '"></script></head><body>'
            . $this->pageRoot($pageId, $revision, $content)
            . '</body></html>';

        return Response::text($document)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', "script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'")
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function dispatchAction(Request $request): Response
    {
        if (strlen($request->body()) > self::MAX_REQUEST_BYTES) {
            throw new HttpException(413, 'The reactive action request is too large.');
        }
        if (preg_match('/^\s*application\/json\s*(?:;|$)/i', $request->header('Content-Type') ?? '') !== 1) {
            throw new HttpException(415, 'Reactive actions require a JSON request body.');
        }
        if (!$this->csrfTokens->isValid($request->header('X-CSRF-Token'))) {
            throw new HttpException(403, 'The reactive action request is not authorized.');
        }
        $this->assertSameOrigin($request);

        try {
            $payload = $request->json();
        } catch (JsonException) {
            throw new HttpException(400, 'The reactive action request is invalid.');
        }

        $requiredKeys = ['page', 'action', 'revision', 'arguments'];
        $allowedKeys = [...$requiredKeys, 'component'];
        if (
            array_is_list($payload)
            || count($payload) < count($requiredKeys)
            || count($payload) > count($allowedKeys)
            || array_diff(array_keys($payload), $allowedKeys) !== []
            || array_diff($requiredKeys, array_keys($payload)) !== []
            || !is_string($payload['page'])
            || preg_match('/^[a-f0-9]{48}$/D', $payload['page']) !== 1
            || !is_string($payload['action'])
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $payload['action']) !== 1
            || !is_int($payload['revision'])
            || $payload['revision'] < 0
            || !is_array($payload['arguments'])
            || ($payload['arguments'] !== [] && array_is_list($payload['arguments']))
            || (array_key_exists('component', $payload) && (
                !is_string($payload['component'])
                || preg_match('/^[a-f0-9]{48}$/D', $payload['component']) !== 1
            ))
        ) {
            throw new HttpException(400, 'The reactive action request has an invalid shape.');
        }

        $pages = $this->pages();
        $page = $pages[$payload['page']] ?? null;
        if (!is_array($page) || !isset($page['component'], $page['state'], $page['revision'])) {
            throw new HttpException(404, 'The reactive page is not available in this session.');
        }

        $component = $this->components[$page['component']] ?? null;
        if (!$component instanceof ReactiveComponent || !is_array($page['state'])) {
            throw new RuntimeException('The stored reactive page is invalid.');
        }

        $childId = $payload['component'] ?? null;
        $targetName = $page['component'];
        $targetState = $page['state'];
        if ($childId !== null) {
            $childRecords = $page['children'] ?? null;
            $child = is_array($childRecords) ? ($childRecords[$childId] ?? null) : null;
            if (
                !is_array($child)
                || !isset($child['component'], $child['state'], $child['props'], $child['revision'])
                || !is_string($child['component'])
                || !is_array($child['state'])
                || !is_array($child['props'])
                || !is_int($child['revision'])
            ) {
                throw new HttpException(404, 'The reactive component is not available in this page.');
            }
            $childComponent = $this->components[$child['component']] ?? null;
            if (!$childComponent instanceof ReactiveNestedComponentInterface) {
                throw new RuntimeException('The stored reactive child component is invalid.');
            }
            $targetName = $child['component'];
            $targetState = $child['state'];
        }

        $action = $this->actions[$targetName][$payload['action']] ?? null;
        if (!$action instanceof ReactiveAction) {
            throw new HttpException(404, 'The reactive action is not available.');
        }

        $validation = $action->validateArguments($payload['arguments']);
        if (!$validation->isValid()) {
            return Response::json([
                'error' => 'The reactive action arguments are invalid.',
                'errors' => $validation->errors(),
            ], 422)->withHeader('Cache-Control', 'no-store');
        }
        if (!$action->isAuthorized($targetState, $payload['arguments'])) {
            throw new HttpException(403, 'The reactive action request is not authorized.');
        }

        if ($payload['revision'] !== $page['revision']) {
            $children = $page['children'] ?? [];
            $activeChildren = [];
            $content = $this->renderTree(
                $page['component'],
                $page['state'],
                [],
                $children,
                $activeChildren,
                '',
            );
            $this->disposeInactiveChildren($children, $activeChildren);
            $this->assertPageLimits($page['state'], $children, $content);
            $page['children'] = $children;
            $pages[$payload['page']] = $page;
            $this->session->set(self::PAGE_SESSION_KEY, $pages);

            return Response::json([
                'error' => 'The page changed before this action was applied.',
                'html' => $content,
                'revision' => $page['revision'],
            ], 409)->withHeader('Cache-Control', 'no-store');
        }

        $nextState = $action->transition($targetState, $payload['arguments']);
        $this->assertSafeState($nextState);
        $nextRevision = $page['revision'] + 1;
        if ($childId === null) {
            $page['state'] = $nextState;
        } else {
            $page['children'][$childId]['state'] = $nextState;
            $page['children'][$childId]['revision']++;
            $page['children'][$childId]['updated_at'] = time();
        }
        $page['revision'] = $nextRevision;
        $page['updated_at'] = time();
        $children = $page['children'] ?? [];
        $activeChildren = [];
        $content = $this->renderTree(
            $page['component'],
            $page['state'],
            [],
            $children,
            $activeChildren,
            '',
        );
        $this->disposeInactiveChildren($children, $activeChildren);
        $this->assertPageLimits($page['state'], $children, $content);
        $pages[$payload['page']] = [
            'component' => $page['component'],
            'state' => $page['state'],
            'children' => $children,
            'revision' => $nextRevision,
            'updated_at' => time(),
        ];
        $this->session->set(self::PAGE_SESSION_KEY, $pages);

        return Response::json([
            'html' => $content,
            'revision' => $nextRevision,
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function runtimeResponse(): Response
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'reactive' . DIRECTORY_SEPARATOR . 'runtime.js';
        $runtime = file_get_contents($path);
        if ($runtime === false) {
            throw new RuntimeException('The Nora reactive runtime asset is unavailable.');
        }

        return new Response($runtime, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function assertSameOrigin(Request $request): void
    {
        if (strtolower($request->header('Sec-Fetch-Site') ?? '') === 'cross-site') {
            throw new HttpException(403, 'The reactive action request is not authorized.');
        }

        $origin = $request->header('Origin');
        if ($origin === null) {
            return;
        }

        $originParts = parse_url($origin);
        $hostHeader = $request->header('Host');
        if (
            !is_array($originParts)
            || !isset($originParts['scheme'], $originParts['host'])
            || !in_array(strtolower($originParts['scheme']), ['http', 'https'], true)
            || $hostHeader === null
        ) {
            throw new HttpException(403, 'The reactive action request is not authorized.');
        }
        $hostParts = parse_url('http://' . $hostHeader);
        if (!is_array($hostParts) || !isset($hostParts['host'])) {
            throw new HttpException(403, 'The reactive action request is not authorized.');
        }

        $scheme = strtolower($originParts['scheme']);
        $originPort = $originParts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $hostPort = $hostParts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (
            strtolower($originParts['host']) !== strtolower($hostParts['host'])
            || $originPort !== $hostPort
        ) {
            throw new HttpException(403, 'The reactive action request is not authorized.');
        }
    }

    private function pageRoot(string $pageId, int $revision, string $content): string
    {
        return '<main id="nora-root" data-nora-root data-nora-page="' . $pageId
            . '" data-nora-revision="' . $revision . '">' . $content
            . '<div data-nora-status role="status" aria-live="polite"></div></main>';
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $props
     * @param array<string, array<string, mixed>> $children
     * @param array<string, true> $activeChildren
     */
    private function renderTree(
        string $componentName,
        array $state,
        array $props,
        array &$children,
        array &$activeChildren,
        string $parentPath,
    ): string {
        $component = $this->components[$componentName] ?? null;
        if ($component instanceof ReactiveNestedComponentInterface) {
            $html = $component->render($state, $props);
            $definitions = $component->children($state, $props);
        } elseif ($component instanceof ReactiveComponent) {
            $html = $component->render($state);
            $definitions = $component instanceof ReactiveParentComponentInterface
                ? $component->children($state)
                : [];
        } else {
            throw new RuntimeException('A reactive component is not registered.');
        }

        if (!is_array($definitions)) {
            throw new RuntimeException('A reactive component returned an invalid child collection.');
        }
        if (count($definitions) > self::MAX_CHILDREN_PER_PAGE) {
            throw new HttpException(500, 'The reactive component tree exceeds its size limit.');
        }
        $seenKeys = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof ReactiveChildDefinition) {
                throw new RuntimeException('Reactive children must use explicit child definitions.');
            }
            $key = $definition->key();
            if (isset($seenKeys[$key])) {
                throw new RuntimeException('Reactive child keys must be unique within their parent.');
            }
            $seenKeys[$key] = true;

            $childPath = $parentPath === '' ? $key : $parentPath . '/' . $key;
            $childName = $definition->component();
            $childComponent = $this->components[$childName] ?? null;
            if (!$childComponent instanceof ReactiveNestedComponentInterface) {
                throw new RuntimeException('A nested child must be a registered nested component.');
            }

            $props = $definition->props();
            $rules = $childComponent->propsRules();
            $allowedProps = [];
            foreach (array_keys($rules) as $field) {
                $allowedProps[explode('.', $field, 2)[0]] = true;
            }
            foreach (array_keys($props) as $propName) {
                if (!is_string($propName) || !isset($allowedProps[$propName])) {
                    throw new RuntimeException('A reactive child received an undeclared prop.');
                }
            }
            $propValidation = (new \Nora\Validation\Validator())->validate($props, $rules);
            if (!$propValidation->isValid()) {
                throw new RuntimeException('A reactive child received invalid props.');
            }
            $this->assertSafeState($props);

            $childId = null;
            foreach ($children as $existingId => $existing) {
                if (($existing['path'] ?? null) === $childPath) {
                    $childId = $existingId;
                    break;
                }
            }
            if (
                $childId !== null
                && (
                    ($children[$childId]['component'] ?? null) !== $childName
                    || ($children[$childId]['props'] ?? null) !== $props
                )
            ) {
                $this->disposeChild($childId, $children[$childId]);
                unset($children[$childId]);
                $childId = null;
            }

            if ($childId === null) {
                $childId = bin2hex(random_bytes(24));
                $childState = $childComponent->initialState($props);
                $this->assertSafeState($childState);
                $children[$childId] = [
                    'path' => $childPath,
                    'key' => $key,
                    'component' => $childName,
                    'props' => $props,
                    'state' => $childState,
                    'revision' => 0,
                    'updated_at' => time(),
                ];
                if ($childComponent instanceof ReactiveComponentLifecycleInterface) {
                    $childComponent->mounted($childId, $props, $childState);
                }
            }

            $activeChildren[$childId] = true;
            $childRecord = $children[$childId];
            $childHtml = $this->renderTree(
                $childName,
                $childRecord['state'],
                $props,
                $children,
                $activeChildren,
                $childPath,
            );
            $marker = ReactiveChildDefinition::placeholder($key);
            if (substr_count($html, $marker) !== 1) {
                throw new RuntimeException('Each reactive child placeholder must appear exactly once.');
            }
            $wrapper = '<div data-nora-child-id="' . $childId
                . '" data-nora-child-type="' . htmlspecialchars($childName, ENT_QUOTES, 'UTF-8')
                . '" data-nora-child-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">'
                . $childHtml . '</div>';
            $html = str_replace($marker, $wrapper, $html);
        }

        return $html;
    }

    /**
     * @param array<string, array<string, mixed>> $children
     * @param array<string, true> $activeChildren
     */
    private function disposeInactiveChildren(array &$children, array $activeChildren): void
    {
        foreach ($children as $childId => $child) {
            if (!isset($activeChildren[$childId])) {
                $this->disposeChild($childId, $child);
                unset($children[$childId]);
            }
        }
    }

    /**
     * @param array<string, mixed> $child
     */
    private function disposeChild(string $childId, array $child): void
    {
        $component = $this->components[$child['component'] ?? ''] ?? null;
        if (
            $component instanceof ReactiveComponentLifecycleInterface
            && is_array($child['props'] ?? null)
            && is_array($child['state'] ?? null)
        ) {
            $component->disposed($childId, $child['props'], $child['state']);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, array<string, mixed>> $children
     */
    private function assertPageLimits(array $state, array $children, string $html): void
    {
        if (count($children) > self::MAX_CHILDREN_PER_PAGE) {
            throw new HttpException(500, 'The reactive component tree exceeds its size limit.');
        }
        if (strlen($html) > self::MAX_RENDERED_BYTES) {
            throw new HttpException(500, 'The reactive component output is too large.');
        }

        $pageState = ['root' => $state];
        foreach ($children as $identity => $child) {
            $pageState['children'][$identity] = [
                'props' => $child['props'],
                'state' => $child['state'],
            ];
        }
        try {
            $encoded = json_encode($pageState, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Reactive page state cannot be encoded safely.', 0, $exception);
        }
        if (strlen($encoded) > self::MAX_STATE_BYTES) {
            throw new \InvalidArgumentException('Reactive page state exceeds the configured size limit.');
        }
    }

    /**
     * @return array<string, array{component: string, state: array<string, mixed>, revision: int, updated_at: int}>
     */
    private function pages(): array
    {
        $pages = $this->session->get(self::PAGE_SESSION_KEY, []);
        if (!is_array($pages)) {
            throw new RuntimeException('The stored reactive page collection is invalid.');
        }

        $now = time();
        $originalCount = count($pages);
        $changed = false;
        foreach ($pages as $pageId => $page) {
            if (
                !is_string($pageId)
                || !is_array($page)
                || !isset($page['updated_at'])
                || !is_int($page['updated_at'])
            ) {
                throw new RuntimeException('A stored reactive page record is invalid.');
            }
            if ($page['updated_at'] < $now - self::PAGE_TTL_SECONDS) {
                foreach (is_array($page['children'] ?? null) ? $page['children'] : [] as $childId => $child) {
                    if (is_string($childId) && is_array($child)) {
                        $this->disposeChild($childId, $child);
                    }
                }
                unset($pages[$pageId]);
                $changed = true;
                continue;
            }
            if (!isset($page['children'])) {
                $pages[$pageId]['children'] = [];
                $changed = true;
            }
        }
        if ($changed || count($pages) !== $originalCount) {
            $this->session->set(self::PAGE_SESSION_KEY, $pages);
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function assertSafeState(array $state): void
    {
        $walk = static function (mixed $value, int $depth = 0) use (&$walk): void {
            if ($depth > 32) {
                throw new \InvalidArgumentException('Reactive state is nested too deeply.');
            }
            if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
                return;
            }
            if (is_float($value) && is_finite($value)) {
                return;
            }
            if (!is_array($value)) {
                throw new \InvalidArgumentException('Reactive state may contain only JSON-safe values.');
            }
            foreach ($value as $key => $child) {
                if (!is_int($key) && !is_string($key)) {
                    throw new \InvalidArgumentException('Reactive state keys must be strings or integers.');
                }
                $walk($child, $depth + 1);
            }
        };
        $walk($state);

        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Reactive state cannot be encoded safely.', 0, $exception);
        }
        if (strlen($encoded) > self::MAX_STATE_BYTES) {
            throw new \InvalidArgumentException('Reactive state exceeds the configured size limit.');
        }
    }
}
