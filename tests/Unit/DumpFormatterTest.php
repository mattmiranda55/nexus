<?php

namespace Tests\Unit;

use App\Services\DumpFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;

class DumpFormatterTest extends TestCase
{
    private function format(mixed $value, array $context = []): array
    {
        $data = (new VarCloner)->cloneVar($value);

        return (new DumpFormatter)->format($data, $context);
    }

    public function test_formats_an_array_dump_as_text(): void
    {
        $entry = $this->format(['id' => 7, 'name' => 'matt']);

        $this->assertSame('dump', $entry['type']);
        $this->assertStringContainsString('array:2', $entry['text']);
        $this->assertStringContainsString('"name" => "matt"', $entry['text']);
        $this->assertNotEmpty($entry['ts']);
    }

    public function test_passes_the_source_context_through(): void
    {
        $entry = $this->format('x', [
            'source' => ['name' => 'web.php', 'file' => '/app/routes/web.php', 'line' => 12],
        ]);

        $this->assertSame('/app/routes/web.php', $entry['source']['file']);
        $this->assertSame(12, $entry['source']['line']);
        $this->assertSame('web.php', $entry['source']['name']);
    }

    public function test_missing_source_becomes_nulls_not_errors(): void
    {
        $entry = $this->format(42);

        $this->assertNull($entry['source']['file']);
        $this->assertNull($entry['source']['line']);
    }

    public function test_scalar_dumps_keep_their_representation(): void
    {
        $this->assertStringContainsString('true', $this->format(true)['text']);
        $this->assertStringContainsString('42', $this->format(42)['text']);
    }

    public function test_oversized_dumps_are_truncated(): void
    {
        $entry = $this->format(str_repeat('x', 100_000));

        $this->assertLessThan(70_000, strlen($entry['text']));
        $this->assertStringEndsWith('… (truncated)', $entry['text']);
    }
}
