<?php

namespace App\Services;

/**
 * File-backed hand-off between POST /tinker and the `nexus:tinker-run` worker.
 *
 * The request writes the job (project + code) and returns immediately; the
 * worker, an Electron-side ChildProcess, reads it, runs tinker and writes the
 * result next to it. Files rather than argv because the code and preamble can
 * exceed cmd.exe's ~8KB command-line limit, and rather than ChildProcess stdin
 * because NativePHP can't close a child's stdin to signal EOF.
 */
class TinkerJobs
{
    /** Printed by the worker once the result file is written. */
    public const DONE = '__NEXUS_TINKER_DONE__';

    /**
     * After this long with no result, the worker is presumed dead: TinkerRunner
     * gives up at 60s, plus margin for the worker booting Laravel.
     */
    public const STALE_AFTER = 90;

    /** Leftovers (an abandoned run, a closed window) are swept after this. */
    private const PRUNE_AFTER = 3600;

    public function __construct(private ?string $directory = null) {}

    public static function alias(string $id): string
    {
        return 'tinker-'.$id;
    }

    /** Ids come from the client and become file names, so only UUIDs pass. */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    /** @param  array{project_id: int, code: string}  $job */
    public function create(string $id, array $job): void
    {
        $this->prune();
        $this->write($this->path($id, 'job'), $job);
    }

    public function exists(string $id): bool
    {
        return is_file($this->path($id, 'job')) || is_file($this->path($id, 'result'));
    }

    public function job(string $id): ?array
    {
        return $this->read($this->path($id, 'job'));
    }

    /** When the job was queued, or null if there is none. */
    public function queuedAt(string $id): ?int
    {
        $path = $this->path($id, 'job');

        return is_file($path) ? (filemtime($path) ?: null) : null;
    }

    public function complete(string $id, array $result): void
    {
        $this->write($this->path($id, 'result'), $result);
    }

    public function result(string $id): ?array
    {
        return $this->read($this->path($id, 'result'));
    }

    public function forget(string $id): void
    {
        @unlink($this->path($id, 'job'));
        @unlink($this->path($id, 'result'));
    }

    public function prune(): void
    {
        $cutoff = time() - self::PRUNE_AFTER;

        // Everything, not just *.json: a worker stopped mid-write leaves a .tmp.
        foreach (glob($this->directory().DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if ((filemtime($file) ?: 0) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function directory(): string
    {
        return $this->directory ?? storage_path('app'.DIRECTORY_SEPARATOR.'tinker-runs');
    }

    private function path(string $id, string $kind): string
    {
        if (! self::isValidId($id)) {
            throw new \InvalidArgumentException('Invalid tinker run id');
        }

        return $this->directory().DIRECTORY_SEPARATOR.strtolower($id).'.'.$kind.'.json';
    }

    private function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Write to a temp file and rename it into place, so a reader polling for
     * the result never sees a half-written file.
     */
    private function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, $json);

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write '.$path);
        }
    }
}
