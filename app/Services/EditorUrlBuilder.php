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
        return match ($editor) {
            'vscode' => "vscode://file{$file}:{$line}",
            'vscodium' => "vscodium://file{$file}:{$line}",
            'cursor' => "cursor://file{$file}:{$line}",
            'sublime' => 'subl://open?url=file://'.rawurlencode($file)."&line={$line}",
            'textmate' => 'txmt://open?url=file://'.rawurlencode($file)."&line={$line}",
            default => 'phpstorm://open?file='.rawurlencode($file)."&line={$line}",
        };
    }
}
