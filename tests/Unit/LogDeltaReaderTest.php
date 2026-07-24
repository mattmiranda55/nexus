<?php

namespace Tests\Unit;

use App\Services\LogDeltaReader;
use PHPUnit\Framework\TestCase;

class LogDeltaReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'nexus-log-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_reads_only_bytes_appended_after_the_offset(): void
    {
        file_put_contents($this->path, "old line\n");
        $reader = new LogDeltaReader;

        $before = $reader->size($this->path);
        file_put_contents($this->path, "new line\n", FILE_APPEND);

        $this->assertSame("new line\n", $reader->read($this->path, $before));
    }

    public function test_returns_null_when_nothing_was_appended(): void
    {
        file_put_contents($this->path, "unchanged\n");
        $reader = new LogDeltaReader;

        $this->assertNull($reader->read($this->path, $reader->size($this->path)));
    }

    public function test_returns_null_when_the_file_shrank_after_rotation(): void
    {
        file_put_contents($this->path, str_repeat('x', 500));
        $reader = new LogDeltaReader;
        $before = $reader->size($this->path);

        // Simulate rotation: the file is replaced with something smaller.
        file_put_contents($this->path, "fresh\n");

        $this->assertNull($reader->read($this->path, $before));
    }

    public function test_missing_file_reports_zero_size_and_null_delta(): void
    {
        $reader = new LogDeltaReader;

        $this->assertSame(0, $reader->size('/no/such/file.log'));
        $this->assertNull($reader->read('/no/such/file.log', 0));
    }
}
