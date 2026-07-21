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
    ) {}

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

        // Tinker doesn't want a leading <?php tag.
        $clean = trim($code);
        if (str_starts_with($clean, '<?php')) {
            $clean = trim(substr($clean, 5));
        }

        $process = new Process([$php, 'artisan', 'tinker'], $projectPath);
        $process->setTimeout(60);
        $process->setInput($clean."\n");

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return 'Error: Execution timed out (60s limit)';
        }

        // Approximate Go's CombinedOutput by concatenating both streams.
        $output = $process->getOutput().$process->getErrorOutput();

        return $this->parser->parse($output);
    }
}
