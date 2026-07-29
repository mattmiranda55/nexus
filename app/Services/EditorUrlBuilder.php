<?php

namespace App\Services;

/**
 * Builds an editor deep-link (URL scheme) for a `file:line` source location.
 * Path-style schemes take the absolute path inline; query-style schemes take it
 * url-encoded in a parameter.
 */
class EditorUrlBuilder
{
    public function build(string $editor, string $file, int $line = 1): string
    {
        $path = $this->toUrlPath($file);

        return match ($editor) {
            'vscode' => "vscode://file{$path}:{$line}",
            'vscodium' => "vscodium://file{$path}:{$line}",
            'cursor' => "cursor://file{$path}:{$line}",
            'sublime' => 'subl://open?url=file://'.rawurlencode($path)."&line={$line}",
            'textmate' => 'txmt://open?url=file://'.rawurlencode($path)."&line={$line}",
            // Query-style: PhpStorm takes the native path, so no conversion.
            'phpstorm' => 'phpstorm://open?file='.rawurlencode($file)."&line={$line}",
            default => "vscode://file{$path}:{$line}"
        };
    }

    /**
     * Path-style schemes are concatenated straight onto "scheme://file", so the
     * path must be POSIX-shaped and absolute. A Windows path needs both its
     * separators flipped and a leading slash added, or `vscode://file` +
     * `C:\app\Foo.php` glues into the unparseable `vscode://fileC:\app\Foo.php`.
     * POSIX paths already start with "/" and pass through unchanged.
     */
    private function toUrlPath(string $file): string
    {
        $path = str_replace('\\', '/', $file);

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }
}
