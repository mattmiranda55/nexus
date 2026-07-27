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

    public function test_windows_paths_get_forward_slashes_and_a_leading_slash(): void
    {
        $b = new EditorUrlBuilder;

        // Without the leading slash this would glue into "vscode://fileC:\…".
        $this->assertSame(
            'vscode://file/C:/app/Foo.php:12',
            $b->build('vscode', 'C:\\app\\Foo.php', 12),
        );
        $this->assertSame(
            'vscodium://file/C:/app/Foo.php:12',
            $b->build('vscodium', 'C:\\app\\Foo.php', 12),
        );
    }

    public function test_windows_file_urls_are_posix_shaped(): void
    {
        $b = new EditorUrlBuilder;

        $this->assertSame(
            'subl://open?url=file://%2FC%3A%2Fapp%2FFoo.php&line=12',
            $b->build('sublime', 'C:\\app\\Foo.php', 12),
        );
    }

    public function test_phpstorm_keeps_the_native_windows_path(): void
    {
        $b = new EditorUrlBuilder;

        // Query-parameter style, so PhpStorm wants the path as the OS spells it.
        $this->assertSame(
            'phpstorm://open?file=C%3A%5Capp%5CFoo.php&line=12',
            $b->build('phpstorm', 'C:\\app\\Foo.php', 12),
        );
    }
}
