<?php

declare(strict_types=1);

namespace Nora\View;

use RuntimeException;

final class TemplateRenderer
{
    private readonly string $root;

    public function __construct(string $templateDirectory)
    {
        $root = realpath($templateDirectory);
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new RuntimeException('Template directory is missing or unreadable.');
        }

        $this->root = $root;
    }

    /**
     * Templates are plain text files with escaped {{ variable }} interpolation
     * and {% include "component/name" %} directives. PHP is never evaluated.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return $this->renderTemplate($template, $data, []);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $stack
     */
    private function renderTemplate(string $template, array $data, array $stack): string
    {
        if (in_array($template, $stack, true)) {
            throw new RuntimeException('Template include cycle detected: ' . implode(' -> ', [...$stack, $template]));
        }

        $path = $this->resolveTemplate($template);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read template: ' . $template);
        }

        $stack[] = $template;
        $contents = preg_replace_callback(
            '/\{%\\s*include\\s+["\']([A-Za-z0-9_\/-]+)["\']\\s*%\}/',
            fn (array $matches): string => $this->renderTemplate($matches[1], $data, $stack),
            $contents,
        );
        if ($contents === null) {
            throw new RuntimeException('Unable to parse template includes.');
        }

        $rendered = preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*\}\}/',
            fn (array $matches): string => $this->escape($this->valueAtPath($data, $matches[1])),
            $contents,
        );
        if ($rendered === null) {
            throw new RuntimeException('Unable to render template values.');
        }

        return $rendered;
    }

    private function resolveTemplate(string $template): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/D', $template) !== 1) {
            throw new RuntimeException('Invalid template name.');
        }

        $path = realpath($this->root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $template) . '.nora');
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Template does not exist or is unreadable: ' . $template);
        }

        $prefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $insideRoot = DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($path), strtolower($prefix))
            : str_starts_with($path, $prefix);
        if (!$insideRoot) {
            throw new RuntimeException('Template path resolves outside the template directory.');
        }

        return $path;
    }

    private function valueAtPath(array $data, string $path): string
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return '';
            }
            $value = $value[$segment];
        }

        if ($value === null || is_bool($value)) {
            return $value === true ? '1' : '';
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new RuntimeException('Template values must be scalar or null: ' . $path);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
