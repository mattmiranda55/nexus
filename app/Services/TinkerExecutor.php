<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Run;

/**
 * One tinker run end to end: execute the code in the project, correlate it
 * with what it wrote to the project's log, record it in run history, and shape
 * the payload the console renders.
 *
 * Shared by the background worker (`nexus:tinker-run`) and the synchronous
 * fallback in TinkerController, so both paths produce the same response.
 */
class TinkerExecutor
{
    public function __construct(
        private TinkerRunner $runner,
        private LogDeltaReader $logs,
    ) {}

    /** @return array{envelope: ?array, raw: string, output: string, loggedDuringRun: ?string} */
    public function execute(Project $project, string $code): array
    {
        // A4 run↔log correlation: snapshot the log size, then read exactly what
        // this run appended to it — fusing the REPL and the log viewer.
        $logPath = file_exists($project->logPath()) ? $project->logPath() : $project->legacyLogPath();
        $before = $this->logs->size($logPath);

        $started = hrtime(true);
        $result = $this->runner->runStructured($project->path, $code);

        // Run history: an envelope means the code executed to completion and
        // produced a structured result; its absence means tinker bailed early
        // (parse error, exception, missing binary).
        Run::record(
            $project->id,
            $code,
            $result['envelope'] !== null,
            (int) ((hrtime(true) - $started) / 1_000_000),
        );

        return [
            'envelope' => $result['envelope'],
            'raw' => $result['raw'],
            // Back-compat alias: the raw/CLI-parity view is the old `output`.
            'output' => $result['raw'],
            'loggedDuringRun' => $this->logs->read($logPath, $before),
        ];
    }

    /** A run that never reached tinker, in the same shape as execute(). */
    public static function failure(string $message): array
    {
        return [
            'envelope' => null,
            'raw' => 'Error: '.$message,
            'output' => 'Error: '.$message,
            'loggedDuringRun' => null,
        ];
    }
}
