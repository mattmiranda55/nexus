<?php

namespace App\Services;

/**
 * Parses `php artisan migrate:status` text output into rows. That command has
 * no `--json`, so we read its dotted-leader table:
 *
 *   0001_01_01_000000_create_users_table ....................... [1] Ran
 *   2026_07_21_000000_add_editor_to_settings ....................... Pending
 */
class MigrateStatusParser
{
    /**
     * @return array<int, array{name: string, batch: ?int, ran: bool}>
     */
    public function parse(string $output): array
    {
        $rows = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            // Migration name, dotted leader, optional [batch], then Ran/Pending.
            if (! preg_match('/^\s*(\S.*?)\s*\.{2,}\s*(?:\[(\d+)\]\s*)?(Ran|Pending)\s*$/', $line, $m)) {
                continue;
            }

            $rows[] = [
                'name' => $m[1],
                'batch' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null,
                'ran' => $m[3] === 'Ran',
            ];
        }

        return $rows;
    }
}
