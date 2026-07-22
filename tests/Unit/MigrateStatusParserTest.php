<?php

namespace Tests\Unit;

use App\Services\MigrateStatusParser;
use PHPUnit\Framework\TestCase;

class MigrateStatusParserTest extends TestCase
{
    public function test_parses_ran_and_pending_rows(): void
    {
        $output = <<<'TXT'

          Migration name .................................................... Batch / Status
          0001_01_01_000000_create_users_table ...................................... [1] Ran
          2026_07_19_202352_create_settings_table ................................... [1] Ran
          2026_07_21_000000_add_editor_and_notify_to_settings ......................... Pending

        TXT;

        $rows = (new MigrateStatusParser)->parse($output);

        $this->assertCount(3, $rows);
        $this->assertSame('0001_01_01_000000_create_users_table', $rows[0]['name']);
        $this->assertSame(1, $rows[0]['batch']);
        $this->assertTrue($rows[0]['ran']);

        $this->assertSame('2026_07_21_000000_add_editor_and_notify_to_settings', $rows[2]['name']);
        $this->assertNull($rows[2]['batch']);
        $this->assertFalse($rows[2]['ran']);
    }

    public function test_ignores_non_migration_lines(): void
    {
        $this->assertSame([], (new MigrateStatusParser)->parse("Nothing to migrate.\n\n"));
    }
}
