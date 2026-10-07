<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a snippet of PHP through `php artisan tinker` in a project directory,
 * piping the code to tinker's stdin and returning parsed output.
 *
 * Ported from the original Go RunTinker.
 */
class TinkerRunner
{
    public function __construct(
        private PhpBinaryResolver $resolver,
        private TinkerOutputParser $parser,
        private TinkerResultSerializer $serializer,
        private TinkerScript $script,
        private TargetEnvironment $environment,
    ) {}

    /**
     * Structured run: pipe the user code through tinker wrapped in the
     * serializer preamble/emitter, then return both the typed envelope of the
     * last-evaluated value and the cleaned raw output (the CLI-parity fallback).
     *
     * @return array{envelope: ?array, raw: string, error: ?string}
     */
    public function runStructured(string $projectPath, string $code): array
    {
        $projectPath = rtrim($projectPath, '/\\');

        if (! is_file($projectPath.DIRECTORY_SEPARATOR.'artisan')) {
            return $this->failure('Invalid Laravel project path');
        }

        try {
            $php = $this->resolver->resolve($projectPath);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage());
        }

        $script = $this->script->capture($code);

        $stdin = $this->serializer->preamble()
            .$script['code']."\n"
            .$this->serializer->emitter()
            // Last line on purpose: PsySH prints "= …" only for the final
            // statement, and that line is what the raw view shows.
            .($script['captured'] ? '$'.TinkerScript::RESULT_VAR.";\n" : '');

        $process = new Process([$php, 'artisan', 'tinker'], $projectPath, $this->environment->isolate());
        $process->setTimeout(60);
        $process->setInput($stdin);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->failure('Execution timed out (60s limit)');
        }

        $stdout = $process->getOutput();
        $envelope = $this->extractEnvelope($stdout);

        // An exception aborts the emitter, but the trailing result line still
        // runs against an unset variable and prints "= null" — ours, not theirs.
        if ($script['captured'] && $envelope === null) {
            $stdout = preg_replace('/(?:^|\R)(?:> )?= null\s*$/', '', $stdout) ?? $stdout;
        }

        $raw = $this->stripMachinery($stdout);
        if (($stderr = $process->getErrorOutput()) !== '') {
            $raw = rtrim($raw, "\r\n")."\n".$stderr;
        }

        return [
            'envelope' => $envelope,
            'raw' => $this->parser->parse($raw, preg_split('/\R/', $stdin) ?: []),
            'error' => null,
        ];
    }

    /** Pull the JSON envelope out from between the emitter's sentinels. */
    private function extractEnvelope(string $output): ?array
    {
        // START is printed before any payload, so the first is real; END may
        // legitimately appear inside serialized data, so take the last one.
        $start = strpos($output, TinkerResultSerializer::START);
        $end = strrpos($output, TinkerResultSerializer::END);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $offset = $start + strlen(TinkerResultSerializer::START);
        $json = substr($output, $offset, $end - $offset);
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Cut the emitter's sentinel-wrapped payload out so the raw view shows only
     * the user's own dumps and results — keeping the "= …" line after it.
     */
    private function stripMachinery(string $output): string
    {
        $start = strpos($output, TinkerResultSerializer::START);
        $end = strrpos($output, TinkerResultSerializer::END);

        if ($start === false) {
            return $output;
        }

        // No END means the payload was cut short; drop everything after START.
        $tail = $end === false || $end < $start
            ? ''
            : substr($output, $end + strlen(TinkerResultSerializer::END));

        return substr($output, 0, $start).$tail;
    }

    /** @return array{envelope: null, raw: string, error: string} */
    private function failure(string $message): array
    {
        return ['envelope' => null, 'raw' => 'Error: '.$message, 'error' => $message];
    }
}
