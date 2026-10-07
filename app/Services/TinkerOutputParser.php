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
    /**
     * @param  ?array<int, string>  $input  The lines piped to tinker. With them
     *                                      the echoed input is removed exactly and all real output is kept;
     *                                      without them a prompt-stripping heuristic is used.
     */
    public function parse(string $output, ?array $input = null): string
    {
        $final = $input === null
            ? $this->heuristic($output)
            : $this->exact($output, $input);

        return $final === '' ? 'null' : $final;
    }

    /**
     * Piped PsySH echoes every input line behind a "> " / ". " prompt first,
     * then prints all output starting on one final prompt line. So: skip the
     * leading lines that are echoes of what we sent, strip the single prompt
     * the output starts on, and keep everything after verbatim — including
     * `echo`/`dump()` text that the heuristic would mistake for input.
     *
     * @param  array<int, string>  $input
     */
    private function exact(string $output, array $input): string
    {
        $sent = array_values(array_filter(array_map('trim', $input), fn ($line) => $line !== ''));

        // A bare \r is PsySH redrawing a truncated echo line in place, not a
        // line break: "> \r<…tail" must stay one prompt line.
        $output = str_replace(["\r\n", "\r"], ["\n", ''], $output);
        $lines = explode("\n", $output);
        $count = count($lines);
        $i = 0;

        for (; $i < $count; $i++) {
            $trimmed = trim($lines[$i]);
            if ($trimmed === '') {
                continue;
            }

            $content = $this->promptContent($trimmed);
            if ($content === null) {
                break; // output that didn't start on a prompt line
            }
            if ($content === '' || $this->isEcho($content, $sent)) {
                continue;
            }

            $lines[$i] = $content; // first output line, prompt removed
            break;
        }

        return trim(implode("\n", $this->unwrapResults(array_slice($lines, $i))), "\n");
    }

    /**
     * Turn PsySH's "= value" result blocks into plain values. Continuation
     * lines of a multi-line result are indented to sit under "= ", so they
     * lose those two columns too.
     *
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function unwrapResults(array $lines): array
    {
        $out = [];
        $inResult = false;

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (str_starts_with($line, '= ')) {
                $out[] = substr($line, 2);
                $inResult = true;

                continue;
            }

            if ($inResult && str_starts_with($line, '  ')) {
                $out[] = substr($line, 2);

                continue;
            }

            $inResult = false;
            $out[] = $line;
        }

        return $out;
    }

    /** The text after a leading "> " / ". " prompt, or null if there's none. */
    private function promptContent(string $line): ?string
    {
        if (! preg_match('/^[>.](?:\s+(.*))?$/s', $line, $m)) {
            return null;
        }

        return trim($m[1] ?? '');
    }

    /**
     * Is this prompt line just PsySH echoing one of our input lines? Long lines
     * are echoed truncated from the left behind a "<" marker.
     *
     * @param  array<int, string>  $sent
     */
    private function isEcho(string $content, array $sent): bool
    {
        if (in_array($content, $sent, true)) {
            return true;
        }

        if (! str_starts_with($content, '<') || strlen($content) < 2) {
            return false;
        }

        $suffix = substr($content, 1);
        foreach ($sent as $line) {
            if (str_ends_with($line, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function heuristic(string $output): string
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

        return trim(implode("\n", $result));
    }
}
