<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\View\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewTest extends TestCase
{
    public function testRendersEscapedValuesAndReusableIncludes(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-views-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory));
        self::assertTrue(mkdir($directory . DIRECTORY_SEPARATOR . 'components'));
        file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'home.nora',
            '<h1>{{ user.name }}</h1>{% include "components/card" %}',
        );
        file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'card.nora',
            '<p>{{ message }}</p>',
        );

        try {
            $html = (new TemplateRenderer($directory))->render('home', [
                'user' => ['name' => '<script>alert(1)</script>'],
                'message' => '"quoted" & <tag>',
            ]);
            self::assertSame(
                '<h1>&lt;script&gt;alert(1)&lt;/script&gt;</h1><p>&quot;quoted&quot; &amp; &lt;tag&gt;</p>',
                $html,
            );
        } finally {
            unlink($directory . DIRECTORY_SEPARATOR . 'home.nora');
            unlink($directory . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'card.nora');
            rmdir($directory . DIRECTORY_SEPARATOR . 'components');
            rmdir($directory);
        }
    }

    public function testRejectsPathTraversalAndIncludeCycles(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-views-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory));
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'cycle.nora', '{% include "cycle" %}');

        try {
            $renderer = new TemplateRenderer($directory);
            try {
                $renderer->render('../outside');
                self::fail('Expected path traversal to be rejected.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('cycle detected');
            $renderer->render('cycle');
        } finally {
            unlink($directory . DIRECTORY_SEPARATOR . 'cycle.nora');
            rmdir($directory);
        }
    }

    public function testRejectsNonScalarTemplateValues(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-views-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory));
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'home.nora', '{{ user }}');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must be scalar');
            (new TemplateRenderer($directory))->render('home', ['user' => ['name' => 'Nora']]);
        } finally {
            unlink($directory . DIRECTORY_SEPARATOR . 'home.nora');
            rmdir($directory);
        }
    }
}
