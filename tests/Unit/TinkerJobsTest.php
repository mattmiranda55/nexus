<?php

namespace Tests\Unit;

use App\Services\TinkerJobs;
use PHPUnit\Framework\TestCase;

class TinkerJobsTest extends TestCase
{
    private const ID = '0b6c1d2e-3f40-4a5b-8c6d-7e8f90a1b2c3';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nexus-jobs-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_a_job_and_its_result_round_trip_and_are_forgotten_together(): void
    {
        $jobs = new TinkerJobs($this->dir);

        $jobs->create(self::ID, ['project_id' => 1, 'code' => '1']);
        $jobs->complete(self::ID, ['raw' => "caf\xC3\xA9"]);

        $this->assertTrue($jobs->exists(self::ID));
        $this->assertSame(['project_id' => 1, 'code' => '1'], $jobs->job(self::ID));
        $this->assertSame(['raw' => 'café'], $jobs->result(self::ID));

        $jobs->forget(self::ID);
        $this->assertFalse($jobs->exists(self::ID));
        $this->assertSame([], glob($this->dir.DIRECTORY_SEPARATOR.'*'), 'No temp files left behind');
    }

    public function test_invalid_utf8_output_still_produces_a_result(): void
    {
        $jobs = new TinkerJobs($this->dir);
        $jobs->complete(self::ID, ['raw' => "bad \xB1 byte"]);

        $this->assertStringStartsWith('bad ', $jobs->result(self::ID)['raw']);
    }

    public function test_ids_must_be_uuids_so_they_cannot_escape_the_directory(): void
    {
        $this->assertTrue(TinkerJobs::isValidId(self::ID));
        $this->assertFalse(TinkerJobs::isValidId('../../.env'));
        $this->assertFalse(TinkerJobs::isValidId(self::ID.'/x'));

        $this->expectException(\InvalidArgumentException::class);
        (new TinkerJobs($this->dir))->job('../secrets');
    }

    public function test_old_leftovers_are_pruned(): void
    {
        $jobs = new TinkerJobs($this->dir);
        $jobs->create(self::ID, ['project_id' => 1, 'code' => '1']);
        touch($this->dir.DIRECTORY_SEPARATOR.self::ID.'.job.json', time() - 7200);
        clearstatcache();

        $jobs->prune();

        $this->assertNull($jobs->job(self::ID));
    }

    public function test_pruning_also_sweeps_temp_files_left_by_a_killed_worker(): void
    {
        $jobs = new TinkerJobs($this->dir);
        $jobs->create(self::ID, ['project_id' => 1, 'code' => '1']);
        $tmp = $this->dir.DIRECTORY_SEPARATOR.self::ID.'.result.json.abcd1234.tmp';
        touch($tmp, time() - 7200);

        $jobs->prune();

        $this->assertFileDoesNotExist($tmp);
        $this->assertNotNull($jobs->job(self::ID), 'Fresh files stay');
    }
}
