<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs `php artisan <args>` in a target project and decodes JSON output.
 *
 * Sibling to TinkerRunner: most workbench panels are artisan commands with a
 * `--json` flag, so they don't need the REPL at all — resolve the binary, run
 * the command, hand back the decoded structure (and the raw text as a fallback).
 */
class ArtisanRunner
{
    public function __construct(private PhpBinaryResolver $resolver) {}

    /**
     * @param  array<int, string>  $args  e.g. ['route:list', '--json']
     * @return array{ok: bool, output: string, json: mixed, error: ?string}
     */
    public function run(string $projectPath, array $args, int $timeout = 60): array
    {
        $projectPath = rtrim($projectPath, '/');

        if (! is_file("{$projectPath}/artisan")) {
            return $this->fail('Invalid Laravel project path');
        }

        try {
            $php = $this->resolver->resolve($projectPath);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $process = new Process([$php, 'artisan', ...$args], $projectPath);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->fail("Timed out after {$timeout}s");
        }

        $out = $process->getOutput();
        $err = $process->getErrorOutput();

        if (! $process->isSuccessful() && trim($out) === '') {
            return $this->fail(trim($err) ?: 'artisan '.implode(' ', $args).' failed');
        }

        return ['ok' => true, 'output' => $out, 'json' => $this->decode($out), 'error' => null];
    }

    /**
     * Decode JSON, tolerating leading noise (deprecation notices, warnings) that
     * some environments emit before the payload: fall back to slicing from the
     * first bracket to the last matching one.
     */
    private function decode(string $out): mixed
    {
        $data = json_decode(trim($out), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }

        $slice = strpbrk($out, '[{');
        if ($slice === false) {
            return null;
        }

        $end = max((int) strrpos($slice, ']'), (int) strrpos($slice, '}'));
        if ($end <= 0) {
            return null;
        }

        return json_decode(substr($slice, 0, $end + 1), true);
    }

    /** @return array{ok: false, output: string, json: null, error: string} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'output' => '', 'json' => null, 'error' => $message];
    }
}
