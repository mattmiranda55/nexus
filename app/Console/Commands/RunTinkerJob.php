<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\TinkerExecutor;
use App\Services\TinkerJobs;
use Illuminate\Console\Command;

/**
 * The background half of a tinker run. TinkerController queues the job and
 * starts this as a NativePHP ChildProcess, so the run happens outside the
 * single-threaded PHP server and the rest of the UI stays responsive.
 */
class RunTinkerJob extends Command
{
    protected $signature = 'nexus:tinker-run {id : The run id queued by POST /tinker}';

    protected $description = 'Execute a queued tinker run and store its result';

    public function handle(TinkerJobs $jobs, TinkerExecutor $executor): int
    {
        $id = (string) $this->argument('id');

        if (! TinkerJobs::isValidId($id) || ! ($job = $jobs->job($id))) {
            $this->error('No queued tinker run with that id.');

            return self::FAILURE;
        }

        $project = Project::find($job['project_id'] ?? null);

        try {
            $result = $project
                ? $executor->execute($project, (string) ($job['code'] ?? ''))
                : TinkerExecutor::failure('The project was removed before the run started');
        } catch (\Throwable $e) {
            // Whatever happens, leave a result: the UI is waiting on one.
            $result = TinkerExecutor::failure($e->getMessage());
        }

        $jobs->complete($id, $result);

        // The UI listens for this line on the child's stdout, then fetches.
        $this->line(TinkerJobs::DONE);

        return self::SUCCESS;
    }
}
