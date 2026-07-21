<?php

namespace App\Services;

/**
 * Cleans the raw Psy Shell (tinker) output down to just the meaningful result,
 * stripping REPL prompts (`>`/`.`), echoed input, and shell banners.
 *
 * Ported from the original Go RunTinker output parsing.
 */
class TinkerOutputParser
{
    public function parse(string $output): string
    {
        $result = [];

        foreach (explode("\n", $output) as $line) {
            $trimmed = trim($line);

            // Strip leading prompt characters ("> " or ". ").
            $cleaned = $trimmed;
            while (str_starts_with($cleaned, '> ') || str_starts_with($cleaned, '. ')) {
                $cleaned = trim(substr($cleaned, 2));
            }

            // Skip blanks, bare prompts, the Psy Shell banner, and echoed exits.
            if ($cleaned === ''
                || $cleaned === '.'
                || $cleaned === '>'
                || str_contains($cleaned, 'Psy Shell')
                || $cleaned === 'exit') {
                continue;
            }

            if (str_starts_with($cleaned, '= ')) {
                // A tinker result line, e.g. "= 42".
                $result[] = substr($cleaned, 2);
            } elseif (! str_starts_with($trimmed, '> ') && ! str_starts_with($trimmed, '. ')) {
                // Non-prompt output such as dump()/var_dump() text.
                $result[] = $cleaned;
            }
        }

        $final = trim(implode("\n", $result));

        return $final === '' ? 'null' : $final;
    }
}
