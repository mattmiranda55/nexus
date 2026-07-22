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
        $projectPath = rtrim($projectPath, '/');

        if (! is_file("{$projectPath}/artisan")) {
            return $this->failure('Invalid Laravel project path');
        }

        try {
            $php = $this->resolver->resolve($projectPath);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage());
        }

        $stdin = $this->serializer->preamble()
            .$this->stripPhpTag($code)."\n"
            .$this->serializer->emitter();

        $process = new Process([$php, 'artisan', 'tinker'], $projectPath);
        $process->setTimeout(60);
        $process->setInput($stdin);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->failure('Execution timed out (60s limit)');
        }

        $full = $process->getOutput().$process->getErrorOutput();

        return [
            'envelope' => $this->extractEnvelope($full),
            'raw' => $this->parser->parse($this->stripMachinery($full)),
            'error' => null,
        ];
    }

    public function run(string $projectPath, string $code): string
    {
        $projectPath = rtrim($projectPath, '/');

        if (! is_file("{$projectPath}/artisan")) {
            return 'Error: Invalid Laravel project path';
        }

        try {
            $php = $this->resolver->resolve($projectPath);
        } catch (\Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        $process = new Process([$php, 'artisan', 'tinker'], $projectPath);
        $process->setTimeout(60);
        $process->setInput($this->stripPhpTag($code)."\n");

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return 'Error: Execution timed out (60s limit)';
        }

        // Approximate Go's CombinedOutput by concatenating both streams.
        $output = $process->getOutput().$process->getErrorOutput();

        return $this->parser->parse($output);
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
     * Remove the emitter's output (the sentinel-bearing line onward) so the raw
     * view shows only the user's own dumps/results, not our machinery.
     */
    private function stripMachinery(string $output): string
    {
        $pos = strpos($output, TinkerResultSerializer::START);
        if ($pos === false) {
            return $output;
        }

        $lineStart = strrpos(substr($output, 0, $pos), "\n");

        return $lineStart === false ? '' : substr($output, 0, $lineStart);
    }

    /** @return array{envelope: null, raw: string, error: string} */
    private function failure(string $message): array
    {
        return ['envelope' => null, 'raw' => 'Error: '.$message, 'error' => $message];
    }
}
