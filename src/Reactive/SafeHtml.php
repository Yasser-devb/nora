<?php

declare(strict_types=1);

namespace Nora\Reactive;

use InvalidArgumentException;
use Traversable;

final readonly class SafeHtml
{
    /**
     * @param array<string, scalar|null> $attributes
     * @param list<SafeHtml> $children
     */
    private function __construct(
        private string $kind,
        private string $value = '',
        private array $attributes = [],
        private array $children = [],
    ) {
    }

    public static function text(string|int|float|bool|null $value): self
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('HTML text must be a finite scalar value.');
        }

        return new self('text', $value === null || is_bool($value) ? '' : (string) $value);
    }

    public static function trusted(string $markup): self
    {
        return new self('trusted', $markup);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param mixed $children
     */
    public static function element(string $tag, array $attributes = [], mixed $children = null): self
    {
        if (
            preg_match('/^[A-Za-z][A-Za-z0-9:-]*$/D', $tag) !== 1
            || in_array(strtolower($tag), ['script', 'style'], true)
        ) {
            throw new InvalidArgumentException('The HTML element name is invalid or unsafe.');
        }

        $normalized = [];
        $seenAttributes = [];
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_:][A-Za-z0-9:._-]*$/D', $name) !== 1) {
                throw new InvalidArgumentException('An HTML attribute name is invalid.');
            }
            $normalizedName = strtolower($name);
            if (isset($seenAttributes[$normalizedName])) {
                throw new InvalidArgumentException('HTML attribute names must be unique.');
            }
            $seenAttributes[$normalizedName] = true;
            if (
                str_starts_with($normalizedName, 'on')
                || $normalizedName === 'srcdoc'
                || $normalizedName === 'style'
            ) {
                throw new InvalidArgumentException('Executable HTML attributes are not supported.');
            }
            if (in_array($normalizedName, ['archive', 'imagesrcset', 'ping', 'srcset'], true)) {
                throw new InvalidArgumentException('Multi-URL HTML attributes are not supported.');
            }
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException('HTML attributes must be scalar or null.');
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('HTML attribute values must be finite.');
            }
            if ($value !== null && self::isUrlAttribute($normalizedName) && !self::isSafeUrl((string) $value)) {
                throw new InvalidArgumentException('An HTML URL attribute uses an unsupported URL.');
            }
            $normalized[$name] = $value;
        }
        $httpEquiv = null;
        foreach ($normalized as $name => $value) {
            if (strtolower($name) === 'http-equiv') {
                $httpEquiv = $value;
            }
        }
        if (strtolower($tag) === 'meta' && strtolower((string) $httpEquiv) === 'refresh') {
            throw new InvalidArgumentException('Meta refresh elements are not supported.');
        }

        $children = self::normalizeChildren($children);
        if (in_array(strtolower($tag), [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
            'meta', 'param', 'source', 'track', 'wbr',
        ], true) && $children !== []) {
            throw new InvalidArgumentException('Void HTML elements cannot have children.');
        }

        return new self('element', $tag, $normalized, $children);
    }

    /**
     * @param iterable<mixed> $children
     */
    public static function fragment(iterable $children): self
    {
        return new self('fragment', '', [], self::normalizeChildren($children));
    }

    public function withKey(string|int $key): self
    {
        if ($this->kind !== 'element') {
            throw new InvalidArgumentException('A keyed collection item must render one HTML element.');
        }

        $attributes = $this->attributes;
        $attributes['data-nora-key'] = (string) $key;

        return new self($this->kind, $this->value, $attributes, $this->children);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        if ($this->kind !== 'element') {
            throw new InvalidArgumentException('Attributes can only be added to an HTML element.');
        }

        return self::element($this->value, [...$this->attributes, ...$attributes], $this->children);
    }

    public function isFormControl(): bool
    {
        return $this->kind === 'element'
            && in_array(strtolower($this->value), ['input', 'select', 'textarea'], true);
    }

    public function toHtml(): string
    {
        if ($this->kind === 'text' || $this->kind === 'trusted') {
            return $this->kind === 'text' ? self::escape($this->value) : $this->value;
        }

        if ($this->kind === 'fragment') {
            return implode('', array_map(
                static fn (self $child): string => $child->toHtml(),
                $this->children,
            ));
        }

        $attributes = '';
        foreach ($this->attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                if (in_array(strtolower($name), self::booleanAttributes(), true)) {
                    $attributes .= ' ' . $name . '=""';
                    continue;
                }
                $value = 'true';
            }
            $attributes .= ' ' . $name . '="' . self::escape((string) $value) . '"';
        }

        $tag = $this->value;
        if (in_array(strtolower($tag), [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
            'meta', 'param', 'source', 'track', 'wbr',
        ], true)) {
            return '<' . $tag . $attributes . '>';
        }

        return '<' . $tag . $attributes . '>'
            . implode('', array_map(
                static fn (self $child): string => $child->toHtml(),
                $this->children,
            ))
            . '</' . $tag . '>';
    }

    /**
     * @return list<SafeHtml>
     */
    private static function normalizeChildren(mixed $children): array
    {
        if ($children === null || is_bool($children)) {
            return [];
        }
        if ($children instanceof self) {
            return [$children];
        }
        if (is_string($children) || is_int($children) || is_float($children)) {
            return [self::text($children)];
        }
        if (is_array($children) || $children instanceof Traversable) {
            $normalized = [];
            foreach ($children as $child) {
                array_push($normalized, ...self::normalizeChildren($child));
            }

            return $normalized;
        }

        throw new InvalidArgumentException('HTML children must be safe markup, scalar text, or iterable.');
    }

    private static function isUrlAttribute(string $name): bool
    {
        return in_array(strtolower($name), [
            'action', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction',
            'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap',
            'xlink:href',
        ], true);
    }

    private static function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches) !== 1) {
            return true;
        }

        return in_array(strtolower($matches[1]), ['http', 'https', 'mailto', 'tel'], true);
    }

    /**
     * @return list<string>
     */
    private static function booleanAttributes(): array
    {
        return [
            'allowfullscreen', 'async', 'autofocus', 'autoplay', 'checked', 'controls',
            'default', 'defer', 'disabled', 'formnovalidate', 'hidden', 'inert',
            'ismap', 'itemscope', 'loop', 'multiple', 'muted', 'nomodule', 'novalidate',
            'open', 'playsinline', 'readonly', 'required', 'reversed', 'selected',
        ];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
