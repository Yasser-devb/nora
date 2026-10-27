<?php

declare(strict_types=1);

use Nora\Core\Application;
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Reactive\ReactiveEngine;
use Nora\Security\SessionManager;
use NoraWebsite\Reactive\HomePage;
use NoraWebsite\Reactive\PlaygroundPage;
use NoraWebsite\Site\PageRenderer;
use NoraWebsite\Site\SiteSecurityHeadersMiddleware;

return static function (Application $app): void {
    $templateDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources'
        . DIRECTORY_SEPARATOR . 'views';
    $baseUrl = (string) $app->config()->get('app.base_url', '');
    $pages = new PageRenderer($templateDirectory, $baseUrl);
    $contentSecurityPolicy = "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'";
    $app->middleware(new SiteSecurityHeadersMiddleware($contentSecurityPolicy));

    $html = static function (string $body, int $status = 200): Response {
        return new Response($body, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    };

    $page = static function (
        string $template,
        string $title,
        string $description,
        string $path,
    ) use ($pages, $html): Response {
        return $html($pages->page($template, $title, $description, $path));
    };

    $reactive = new ReactiveEngine(new SessionManager(str_starts_with($baseUrl, 'https://')));
    $reactive->register('website-home', new HomePage($pages->templates()));
    $reactive->mount($app, 'website-home', '/', 'Nora Framework — PHP-first, reactive by design');
    $reactive->register(
        'website-playground',
        new PlaygroundPage($pages->templates()),
    );
    $reactive->mount($app, 'website-playground', '/playground');

    $app->route('GET', '/features', static fn (): Response => $page(
        'pages/features',
        'Features and maturity',
        'Explore Nora framework capabilities and their current implementation status.',
        '/features',
    ));
    $app->route('GET', '/docs/getting-started', static fn (): Response => $page(
        'pages/getting-started',
        'Getting started',
        'Install Nora, scaffold an application, start the local server, and register a route.',
        '/docs/getting-started',
    ));
    $app->route('GET', '/docs', static fn (): Response => $page(
        'pages/docs',
        'Documentation',
        'Practical guides and architecture notes for the Nora PHP framework.',
        '/docs',
    ));
    $app->route('GET', '/architecture', static fn (): Response => $page(
        'pages/architecture',
        'Architecture',
        'Understand Nora’s independent architecture and HTTP request lifecycle.',
        '/architecture',
    ));
    $app->route('GET', '/examples', static fn (): Response => $page(
        'pages/examples',
        'Examples',
        'Examples that demonstrate real Nora functionality and clearly state what remains experimental.',
        '/examples',
    ));
    $app->route('GET', '/roadmap', static fn (): Response => $page(
        'pages/roadmap',
        'Roadmap',
        'Nora’s transparent roadmap for framework reliability, stable APIs, and community growth.',
        '/roadmap',
    ));
    $app->route('GET', '/changelog', static fn (): Response => $page(
        'pages/changelog',
        'Changelog',
        'Inspectable Nora development milestones and repository state.',
        '/changelog',
    ));
    $app->route('GET', '/community', static fn (): Response => $page(
        'pages/community',
        'Community',
        'How to propose changes to Nora while preserving official maintainer authority.',
        '/community',
    ));
    $app->route('GET', '/contact', static fn (): Response => $page(
        'pages/contact',
        'Contact',
        'Public project channels and guidance for private vulnerability reporting.',
        '/contact',
    ));
    $app->fallback(static function (Request $request) use ($pages, $html): Response {
        return $html(
            $pages->page(
                'pages/not-found',
                'Page not found',
                'The requested Nora Framework page could not be found.',
                $request->path(),
                ['requested_path' => $request->path()],
            ),
            404,
        );
    });
};
