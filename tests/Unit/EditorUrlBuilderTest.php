<?php

namespace Tests\Unit;

use App\Services\EditorUrlBuilder;
use PHPUnit\Framework\TestCase;

class EditorUrlBuilderTest extends TestCase
{
    public function test_path_style_schemes_inline_the_absolute_path(): void
    {
        $b = new EditorUrlBuilder;

        $this->assertSame('vscode://file/app/Foo.php:12', $b->build('vscode', '/app/Foo.php', 12));
        $this->assertSame('cursor://file/app/Foo.php:12', $b->build('cursor', '/app/Foo.php', 12));
    }

    public function test_query_style_schemes_encode_the_path(): void
    {
        $b = new EditorUrlBuilder;

        $this->assertSame(
            'phpstorm://open?file=%2Fapp%2FFoo.php&line=12',
            $b->build('phpstorm', '/app/Foo.php', 12),
        );
        $this->assertStringContainsString('subl://open?url=file://%2Fapp', $b->build('sublime', '/app/Foo.php', 12));
    }

    public function test_unknown_editor_falls_back_to_phpstorm(): void
    {
        $b = new EditorUrlBuilder;

        $this->assertStringStartsWith('phpstorm://', $b->build('mystery', '/app/Foo.php'));
    }
}
