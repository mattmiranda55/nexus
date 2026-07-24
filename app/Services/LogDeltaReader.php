<?php

namespace App\Services;

/**
 * Reads the slice of a log file appended between a recorded offset and the
 * current EOF. Powers run↔log correlation: snapshot the size before a tinker
 * run, then read what that run wrote.
 */
class LogDeltaReader
{
    /** Current file size in bytes (0 if absent). */
    public function size(string $path): int
    {
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * Bytes appended since $offset, capped at $cap. Returns null when nothing
     * was appended, the file vanished, or it shrank (rotation/truncation) — so
     * we never misattribute a rotated file's head to the run.
     */
    public function read(string $path, int $offset, int $cap = 200000): ?string
    {
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return null;
        }

        $size = (int) filesize($path);
        if ($size <= $offset) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if ($offset > 0) {
                fseek($handle, $offset);
            }
            $data = fread($handle, min($size - $offset, $cap));
        } finally {
            fclose($handle);
        }

        return ($data !== false && trim($data) !== '') ? $data : null;
    }
}
