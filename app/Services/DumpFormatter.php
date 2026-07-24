<?php

namespace App\Services;

use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Dumper\CliDumper;

/**
 * Turns a VarDumper payload (what a connected project's dump() sent over TCP)
 * into the flat JSON-able entry the Dumps panel renders. Kept separate from
 * the listener command so it's unit-testable without sockets.
 */
class DumpFormatter
{
    /** Guard the UI against someone dumping a 50MB collection. */
    private const MAX_TEXT = 65536;

    public function format(Data $data, array $context): array
    {
        $dumper = new CliDumper;
        $dumper->setColors(false);

        $text = rtrim((string) $dumper->dump($data, true), "\n");
        if (strlen($text) > self::MAX_TEXT) {
            $text = substr($text, 0, self::MAX_TEXT)."\n… (truncated)";
        }

        // SourceContextProvider runs in the *sending* app, so file/line point
        // at the dump() call site over there — exactly what click-to-source wants.
        $source = $context['source'] ?? [];

        return [
            'type' => 'dump',
            'ts' => date('c'),
            'source' => [
                'name' => $source['name'] ?? null,
                'file' => $source['file'] ?? null,
                'line' => $source['line'] ?? null,
            ],
            'text' => $text,
        ];
    }
}
