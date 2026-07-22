<?php

namespace Tests\Unit;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the serializer helpers directly (the same source that gets eval'd
 * into a target project's tinker). Pure logic — no database touched.
 */
class TinkerResultSerializerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/app/Services/tinker_serializer.php';
    }

    public function test_scalars_carry_type_and_value(): void
    {
        $this->assertSame('number', nexus_node(42, 0)['kind']);
        $this->assertSame(42, nexus_node(42, 0)['value']);
        $this->assertSame('bool', nexus_node(true, 0)['kind']);
        $this->assertSame('null', nexus_node(null, 0)['kind']);
        $this->assertSame('integer', nexus_serialize(42)['meta']['phpType']);
    }

    public function test_long_strings_are_truncated_but_report_true_length(): void
    {
        $node = nexus_node(str_repeat('a', NEXUS_MAX_STRING + 50), 0);

        $this->assertTrue($node['truncated']);
        $this->assertSame(NEXUS_MAX_STRING + 50, $node['length']);
        $this->assertSame(NEXUS_MAX_STRING, mb_strlen($node['value']));
    }

    public function test_list_of_assoc_arrays_becomes_a_table(): void
    {
        $value = [
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Alan', 'admin' => true],
        ];
        $table = nexus_serialize($value)['table'];

        $this->assertSame(['id', 'name', 'admin'], $table['columns']);
        $this->assertSame([1, 'Ada', null], $table['rows'][0]);
        $this->assertSame([2, 'Alan', true], $table['rows'][1]);
        $this->assertSame(2, $table['count']);
        $this->assertFalse($table['truncated']);
    }

    public function test_collection_is_tabular_and_scalar_list_is_not(): void
    {
        $collection = new Collection([['a' => 1], ['a' => 2]]);
        $this->assertNotNull(nexus_serialize($collection)['table']);
        $this->assertSame('collection', nexus_serialize($collection)['root']['kind']);

        // A list of scalars is a list, not a grid.
        $this->assertNull(nexus_serialize([1, 2, 3])['table']);
        $this->assertSame('list', nexus_serialize([1, 2, 3])['root']['kind']);
    }

    public function test_assoc_array_is_a_tree_not_a_table(): void
    {
        $env = nexus_serialize(['name' => 'Nexus', 'meta' => ['x' => 1]]);

        $this->assertNull($env['table']);
        $this->assertSame('assoc', $env['root']['kind']);
        $this->assertCount(2, $env['root']['entries']);
        $this->assertSame('meta', $env['root']['entries'][1]['key']);
        $this->assertSame('assoc', $env['root']['entries'][1]['node']['kind']);
    }

    public function test_deep_nesting_is_capped(): void
    {
        $deep = $value = [];
        // Build one array nested well past NEXUS_MAX_DEPTH.
        for ($i = 0; $i < NEXUS_MAX_DEPTH + 3; $i++) {
            $value = ['child' => $value];
        }

        $node = nexus_node($value, 0);
        // Walk down until we hit the collapsed sentinel.
        $depth = 0;
        while (isset($node['entries'][0])) {
            $node = $node['entries'][0]['node'];
            $depth++;
        }

        $this->assertTrue($node['collapsed'] ?? false);
        $this->assertLessThanOrEqual(NEXUS_MAX_DEPTH, $depth);
    }

    public function test_row_collection_is_capped_but_count_is_true_total(): void
    {
        $rows = [];
        for ($i = 0; $i < NEXUS_MAX_ROWS + 25; $i++) {
            $rows[] = ['n' => $i];
        }
        $table = nexus_serialize($rows)['table'];

        $this->assertCount(NEXUS_MAX_ROWS, $table['rows']);
        $this->assertSame(NEXUS_MAX_ROWS + 25, $table['count']);
        $this->assertTrue($table['truncated']);
    }
}
