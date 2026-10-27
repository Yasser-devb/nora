<?php

declare(strict_types=1);

namespace NoraWebsite\Site;

use Nora\View\TemplateRenderer;

final class PageRenderer
{
    private readonly TemplateRenderer $templates;

    public function __construct(
        string $templateDirectory,
        private readonly string $baseUrl,
    ) {
        $this->templates = new TemplateRenderer($templateDirectory);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function page(
        string $template,
        string $title,
        string $description,
        string $path,
        array $data = [],
    ): string {
        $pageData = [
            ...$data,
            'page_title' => $title,
            'document_title' => $title . ' · Nora Framework',
            'description' => $description,
            'canonical_url' => $this->baseUrl === '' ? '' : $this->baseUrl . $path,
        ];

        return $this->templates->render('shared/head', $pageData)
            . $this->templates->render('shared/header', $pageData)
            . '<main id="main-content">' . $this->templates->render($template, $pageData) . '</main>'
            . $this->templates->render('shared/footer', $pageData)
            . '</body></html>';
    }

    public function templates(): TemplateRenderer
    {
        return $this->templates;
    }

    public function errorPage(int $status): string
    {
        return $this->page(
            'pages/server-error',
            $status >= 500 ? 'Something went wrong' : 'Request not completed',
            'Nora could not complete this request.',
            '',
            ['error_code' => $status],
        );
    }
}
