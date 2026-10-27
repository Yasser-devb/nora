<?php

declare(strict_types=1);

namespace Nora\Tests\Integration;

use Nora\Core\Application;
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Reactive\ReactiveAction;
use Nora\Reactive\ReactiveComponent;
use Nora\Reactive\ReactiveEngine;
use Nora\Routing\MiddlewareInterface;
use Nora\Security\SessionManager;
use Nora\View\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class ReactiveLifecycleTest extends TestCase
{
    private string $sessionDirectory;

    private string $previousSessionPath;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::fail('A PHP session was active before the reactive integration test.');
        }

        $this->sessionDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'nora-reactive-session-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($this->sessionDirectory));
        $this->previousSessionPath = session_save_path();
        session_save_path($this->sessionDirectory);
        session_id('');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_id('');
        session_save_path($this->previousSessionPath);
        foreach (glob($this->sessionDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->sessionDirectory);
    }

    public function testServerRenderedPageRunsAnAllowlistedActionAndRejectsStaleReplay(): void
    {
        [$app] = $this->application();
        $page = $app->handle(Request::fromUri('GET', '/'));

        self::assertSame(200, $page->status());
        self::assertStringContainsString('data-nora-root', $page->body());
        self::assertStringContainsString('<script defer src="/_nora/runtime.js"></script>', $page->body());
        self::assertSame('text/html; charset=utf-8', $page->headers()['Content-Type']);
        self::assertSame('no-store', $page->headers()['Cache-Control']);
        self::assertStringContainsString("script-src 'self'", $page->headers()['Content-Security-Policy']);

        $runtime = $app->handle(Request::fromUri('GET', '/_nora/runtime.js'));
        self::assertSame(200, $runtime->status());
        self::assertStringContainsString("document.addEventListener('click'", $runtime->body());
        self::assertSame('application/javascript; charset=utf-8', $runtime->headers()['Content-Type']);

        $pageId = $this->attribute($page->body(), 'data-nora-page');
        $csrf = $this->metaContent($page->body(), 'nora-csrf');
        $request = $this->actionRequest($pageId, $csrf, 0, ['amount' => 2]);
        $updated = $app->handle($request);

        self::assertSame(200, $updated->status());
        self::assertSame(1, $this->json($updated->body())['revision']);
        self::assertSame(2, substr_count($this->json($updated->body())['html'], '<output>2</output>'));

        $replay = $app->handle($request);
        self::assertSame(409, $replay->status());
        self::assertSame(1, $this->json($replay->body())['revision']);
        self::assertSame(2, substr_count($this->json($replay->body())['html'], '<output>2</output>'));
    }

    public function testActionRejectsMissingCsrfForgedPagesAndInvalidArguments(): void
    {
        [$app] = $this->application();
        $page = $app->handle(Request::fromUri('GET', '/'));
        $pageId = $this->attribute($page->body(), 'data-nora-page');
        $csrf = $this->metaContent($page->body(), 'nora-csrf');

        self::assertSame(
            403,
            $app->handle($this->actionRequest($pageId, '', 0, ['amount' => 1]))->status(),
        );
        self::assertSame(
            403,
            $app->handle(Request::fromUri(
                'POST',
                '/_nora/action',
                [
                    'Content-Type' => 'application/json',
                    'X-CSRF-Token' => $csrf,
                    'Origin' => 'https://attacker.example',
                    'Host' => 'nora.example',
                    'Sec-Fetch-Site' => 'cross-site',
                ],
                json_encode([
                    'page' => $pageId,
                    'action' => 'increment',
                    'revision' => 0,
                    'arguments' => ['amount' => 1],
                ], JSON_THROW_ON_ERROR),
            ))->status(),
        );
        self::assertSame(
            422,
            $app->handle($this->actionRequest($pageId, $csrf, 0, [
                'amount' => 1,
                'component' => 'forged',
            ]))->status(),
            'A client-supplied component argument must not be accepted as authority.',
        );
        self::assertSame(
            400,
            $app->handle(Request::fromUri(
                'POST',
                '/_nora/action',
                [
                    'Content-Type' => 'application/json',
                    'X-CSRF-Token' => $csrf,
                ],
                json_encode([
                    'page' => $pageId,
                    'action' => 'increment',
                    'revision' => 0,
                    'arguments' => ['amount' => 1],
                    'component' => 'forged',
                    'state' => ['count' => 100],
                ], JSON_THROW_ON_ERROR),
            ))->status(),
            'Client-supplied component IDs and state must be rejected by the strict request schema.',
        );
        self::assertSame(
            403,
            $app->handle(Request::fromUri(
                'POST',
                '/_nora/action',
                [
                    'Content-Type' => 'application/json',
                    'X-CSRF-Token' => $csrf,
                    'Origin' => 'https://attacker.example',
                    'Host' => 'nora.example',
                    'Sec-Fetch-Site' => 'same-origin',
                ],
                json_encode([
                    'page' => $pageId,
                    'action' => 'increment',
                    'revision' => 0,
                    'arguments' => ['amount' => 1],
                ], JSON_THROW_ON_ERROR),
            ))->status(),
        );
        self::assertSame(
            404,
            $app->handle($this->actionRequest(str_repeat('a', 48), $csrf, 0, ['amount' => 1]))->status(),
        );

        $invalid = $app->handle($this->actionRequest($pageId, $csrf, 0, ['amount' => 1, 'state' => 500]));
        self::assertSame(422, $invalid->status());
        self::assertSame(
            ['error', 'errors'],
            array_keys($this->json($invalid->body())),
        );

        self::assertSame(
            415,
            $app->handle(Request::fromUri(
                'POST',
                '/_nora/action',
                ['X-CSRF-Token' => $csrf],
                '{"page":"invalid"}',
            ))->status(),
        );
        self::assertSame(
            415,
            $app->handle(Request::fromUri(
                'POST',
                '/_nora/action',
                ['Content-Type' => 'application/jsonp', 'X-CSRF-Token' => $csrf],
                '{}',
            ))->status(),
            'JSONP is not an accepted JSON media type.',
        );
        self::assertSame(
            400,
            $app->handle(Request::fromUri(
                'POST',
                '/_nora/action',
                ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf],
                '{',
            ))->status(),
        );
        self::assertSame(
            413,
            $app->handle(Request::fromUri(
                'POST',
                '/_nora/action',
                ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf],
                str_repeat('x', 16385),
            ))->status(),
        );
        self::assertSame(
            404,
            $app->handle($this->actionRequest($pageId, $csrf, 0, ['amount' => 1], 'unknown'))->status(),
        );
    }

    public function testActionWithoutAuthorizationPolicyIsDeniedByDefault(): void
    {
        [$app, , $component] = $this->application(null);
        $page = $app->handle(Request::fromUri('GET', '/'));
        $request = $this->actionRequest(
            $this->attribute($page->body(), 'data-nora-page'),
            $this->metaContent($page->body(), 'nora-csrf'),
            0,
            ['amount' => 1],
        );

        self::assertSame(403, $app->handle($request)->status());
        self::assertSame(0, $component->transitionCalls());
        self::assertSame(403, $app->handle($this->actionRequest(
            $this->attribute($page->body(), 'data-nora-page'),
            $this->metaContent($page->body(), 'nora-csrf'),
            0,
            ['amount' => 2],
        ))->status());
        self::assertSame(0, $component->transitionCalls());
    }

    public function testActionAuthorizationIsCheckedBeforeStateChanges(): void
    {
        [$app, , $component] = $this->application(false);
        $page = $app->handle(Request::fromUri('GET', '/'));
        $request = $this->actionRequest(
            $this->attribute($page->body(), 'data-nora-page'),
            $this->metaContent($page->body(), 'nora-csrf'),
            0,
            ['amount' => 1],
        );

        self::assertSame(403, $app->handle($request)->status());
        self::assertSame(0, $component->transitionCalls());
        $allowedAfterDenial = $app->handle($this->actionRequest(
            $this->attribute($page->body(), 'data-nora-page'),
            $this->metaContent($page->body(), 'nora-csrf'),
            0,
            ['amount' => 2],
        ));
        self::assertSame(200, $allowedAfterDenial->status());
        self::assertSame(1, $component->transitionCalls());
        self::assertSame(
            2,
            substr_count($this->json($allowedAfterDenial->body())['html'], '<output>2</output>'),
        );
    }

    public function testPageStateIsBoundToTheCreatingSession(): void
    {
        [$app] = $this->application();
        $firstPage = $app->handle(Request::fromUri('GET', '/'));
        $firstSession = session_id();

        session_id('');
        $secondPage = $app->handle(Request::fromUri('GET', '/'));
        $secondSession = session_id();
        self::assertNotSame($firstSession, $secondSession);

        $foreignRequest = $this->actionRequest(
            $this->attribute($firstPage->body(), 'data-nora-page'),
            $this->metaContent($secondPage->body(), 'nora-csrf'),
            0,
            ['amount' => 1],
        );
        self::assertSame(404, $app->handle($foreignRequest)->status());
    }

    public function testReactiveSessionStartsBeforePreviouslyRegisteredMiddleware(): void
    {
        $session = new SessionManager(false);
        $app = new Application();
        $app->middleware(new ReactiveSessionProbeMiddleware());
        $engine = new ReactiveEngine($session);
        $engine->register('counter', new IntegrationCounterComponent());
        $engine->mount($app, 'counter', '/');

        self::assertSame(200, $app->handle(Request::fromUri('GET', '/'))->status());
    }

    public function testMountedDocumentTitleCanBeCustomizedAndIsEscaped(): void
    {
        $engine = new ReactiveEngine(new SessionManager(false));
        $engine->register('counter', new IntegrationCounterComponent());
        $app = new Application();
        $engine->mount($app, 'counter', '/', 'Nora <script>alert(1)</script>');

        $response = $app->handle(Request::fromUri('GET', '/'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString(
            '<title>Nora &lt;script&gt;alert(1)&lt;/script&gt;</title>',
            $response->body(),
        );
        self::assertStringNotContainsString('<title>Nora <script>', $response->body());
    }

    public function testTemplateRenderedReactiveStateIsEscapedAfterAnAction(): void
    {
        $template = $this->sessionDirectory . DIRECTORY_SEPARATOR . 'message.nora';
        self::assertNotFalse(file_put_contents($template, '<output>{{ message }}</output>'));
        $component = new IntegrationMessageComponent(new TemplateRenderer($this->sessionDirectory));
        $engine = new ReactiveEngine(new SessionManager(false));
        $engine->register('message', $component);
        $app = new Application();
        $engine->mount($app, 'message', '/');
        $page = $app->handle(Request::fromUri('GET', '/'));

        $updated = $app->handle($this->actionRequest(
            $this->attribute($page->body(), 'data-nora-page'),
            $this->metaContent($page->body(), 'nora-csrf'),
            0,
            ['message' => '<img src=x onerror=alert(1)>'],
            'change',
        ));
        $html = $this->json($updated->body())['html'];

        self::assertSame(200, $updated->status());
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    /**
     * @return array{Application, ReactiveEngine, IntegrationCounterComponent}
     */
    private function application(?bool $authorized = true): array
    {
        $session = new SessionManager(false);
        $component = new IntegrationCounterComponent($authorized);
        $engine = new ReactiveEngine($session);
        $engine->register('counter', $component);
        $app = new Application();
        $engine->mount($app, 'counter', '/');

        return [$app, $engine, $component];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function actionRequest(
        string $page,
        string $csrf,
        int $revision,
        array $arguments,
        string $action = 'increment',
    ): Request
    {
        return Request::fromUri(
            'POST',
            '/_nora/action',
            [
                'Content-Type' => 'application/json',
                'X-CSRF-Token' => $csrf,
            ],
            json_encode([
                'page' => $page,
                'action' => $action,
                'revision' => $revision,
                'arguments' => $arguments,
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function attribute(string $html, string $attribute): string
    {
        self::assertSame(1, preg_match('/' . preg_quote($attribute, '/') . '="([^"]+)"/', $html, $matches));

        return $matches[1];
    }

    private function metaContent(string $html, string $name): string
    {
        self::assertSame(
            1,
            preg_match('/<meta name="' . preg_quote($name, '/') . '" content="([^"]+)"/', $html, $matches),
        );

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

/**
 * @internal
 */
final class IntegrationMessageComponent implements ReactiveComponent
{
    public function __construct(private readonly TemplateRenderer $templates)
    {
    }

    public function initialState(): array
    {
        return ['message' => 'Safe'];
    }

    public function render(array $state): string
    {
        return $this->templates->render('message', $state);
    }

    public function actions(): array
    {
        return [
            'change' => ReactiveAction::publicAction(
                ['message' => 'required|string|max:2000'],
                static fn (array $state, array $arguments): array => ['message' => $arguments['message']],
            ),
        ];
    }

}

/**
 * @internal
 */
final class ReactiveSessionProbeMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('The reactive session middleware did not run first.');
        }

        return $next($request);
    }
}

/**
 * @internal
 */
final class IntegrationCounterComponent implements ReactiveComponent
{
    private int $transitionCalls = 0;

    public function __construct(private readonly ?bool $authorized = true)
    {
    }

    public function initialState(): array
    {
        return ['count' => 0];
    }

    public function render(array $state): string
    {
        $count = htmlspecialchars((string) $state['count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<section data-nora-key="first"><output>' . $count . '</output></section>'
            . '<section data-nora-key="second"><output>' . $count . '</output></section>';
    }

    public function actions(): array
    {
        $arguments = ['amount' => 'integer|min:1|max:5'];
        $transition = function (array $state, array $arguments): array {
            $this->transitionCalls++;

            return [
                'count' => $state['count'] + (int) $arguments['amount'],
            ];
        };
        if ($this->authorized === null) {
            return ['increment' => new ReactiveAction($arguments, $transition)];
        }

        return [
            'increment' => new ReactiveAction(
                $arguments,
                $transition,
                fn (array $state, array $arguments): bool =>
                    $this->authorized || $arguments['amount'] === 2,
            ),
        ];
    }

    public function transitionCalls(): int
    {
        return $this->transitionCalls;
    }
}
