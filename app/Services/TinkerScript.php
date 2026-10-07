<?php

namespace App\Services;

use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;

/**
 * Rewrites the user's code so the value of its final expression lands in a
 * variable Nexus can serialize.
 *
 * PsySH only records a statement's value (and only prints its "= …" line) when
 * that statement is the last thing on its input. Nexus always pipes an emitter
 * line after the user's code, so `$_` came back null for every run. Capturing
 * the value explicitly sidesteps that, and re-evaluating the variable as the
 * very last line restores the "= …" result line the raw view shows.
 *
 * Anything PHP-Parser can't read (PsySH commands like `ls` or `doc`, genuinely
 * broken code) passes through untouched so PsySH can answer it itself.
 */
class TinkerScript
{
    public const RESULT_VAR = '__nexusResult';

    /**
     * @return array{code: string, captured: bool}
     */
    public function capture(string $code): array
    {
        $code = $this->stripPhpTag($code);

        if ($this->isShellCommand($code)) {
            return ['code' => $code, 'captured' => false];
        }

        // PsySH accepts a missing final semicolon; PHP-Parser doesn't. Adding one
        // also matters on its own: without it PsySH reads the next piped line
        // (the emitter) as a continuation and reports a parse error. It goes on
        // its own line so a trailing `// comment` can't swallow it.
        foreach ([$code, $code."\n;"] as $candidate) {
            $stmts = $this->parse($candidate);
            if ($stmts !== null) {
                return $this->rewrite($candidate, $stmts);
            }
        }

        return ['code' => $code, 'captured' => false];
    }

    /**
     * @param  array<int, Stmt>  $stmts
     * @return array{code: string, captured: bool}
     */
    private function rewrite(string $code, array $stmts): array
    {
        $last = null;
        foreach (array_reverse($stmts) as $stmt) {
            if (! $stmt instanceof Stmt\Nop) {
                $last = $stmt;
                break;
            }
        }

        if (! $last instanceof Stmt\Expression) {
            return ['code' => $code, 'captured' => false];
        }

        // Positions are offsets into "<?php\n".$code.
        $offset = strlen($this->opening());
        $start = $last->expr->getStartFilePos() - $offset;
        $end = $last->expr->getEndFilePos() - $offset;

        if ($start < 0 || $end < $start) {
            return ['code' => $code, 'captured' => false];
        }

        $rewritten = substr($code, 0, $start)
            .'$'.self::RESULT_VAR.' = ('.substr($code, $start, $end - $start + 1).')'
            .substr($code, $end + 1);

        return ['code' => $rewritten, 'captured' => true];
    }

    /**
     * PsySH's own commands (`ls`, `doc User`, `show $x`, `wtf`, ...). Several of
     * them also parse as PHP — `ls` is a constant fetch, `ls -al` a subtraction —
     * so they must be recognised before PHP-Parser gets a say. A command is a
     * single line whose first word is a command name not followed by
     * something that makes it PHP (a call, assignment or `::`).
     */
    private function isShellCommand(string $code): bool
    {
        if (str_contains($code, "\n")) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:help|\?|ls|dir|dump|doc|rtfm|man|show|wtf|last-exception|whereami|throw-up|timeit|trace|'
            .'history|hist|buffer|buf|clear|edit|sudo|copy|exit|quit|q)(?=\s|$)(?!\s*(?:\(|=[^=>]|::|->))/i',
            $code,
        );
    }

    /** @return ?array<int, Stmt> */
    private function parse(string $code): ?array
    {
        try {
            return (new ParserFactory)->createForNewestSupportedVersion()->parse($this->opening().$code);
        } catch (Error) {
            return null;
        }
    }

    private function opening(): string
    {
        return "<?php\n";
    }

    /** Tinker doesn't want a leading `<?php` tag. */
    private function stripPhpTag(string $code): string
    {
        $clean = trim($code);

        if (str_starts_with($clean, '<?php')) {
            $clean = trim(substr($clean, 5));
        }

        return $clean;
    }
}
