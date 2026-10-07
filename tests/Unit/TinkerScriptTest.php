<?php

namespace Tests\Unit;

use App\Services\TinkerScript;
use PHPUnit\Framework\TestCase;

class TinkerScriptTest extends TestCase
{
    private function capture(string $code): array
    {
        return (new TinkerScript)->capture($code);
    }

    public function test_the_final_expression_is_assigned_to_the_result_variable(): void
    {
        $script = $this->capture("\$a = 40;\n\$a + 2;");

        $this->assertTrue($script['captured']);
        $this->assertSame("\$a = 40;\n\$__nexusResult = (\$a + 2);", $script['code']);
    }

    public function test_a_missing_final_semicolon_is_supplied_on_its_own_line(): void
    {
        $script = $this->capture('User::count()');

        $this->assertTrue($script['captured']);
        $this->assertSame("\$__nexusResult = (User::count())\n;", $script['code']);
    }

    public function test_a_trailing_comment_cannot_swallow_the_supplied_semicolon(): void
    {
        $script = $this->capture('2 + 3 // sum');

        $this->assertTrue($script['captured']);
        $this->assertSame("\$__nexusResult = (2 + 3) // sum\n;", $script['code']);
    }

    public function test_multi_line_expressions_are_wrapped_whole(): void
    {
        $script = $this->capture("collect([1, 2])\n    ->map(fn (\$x) => \$x * 2);");

        $this->assertSame("\$__nexusResult = (collect([1, 2])\n    ->map(fn (\$x) => \$x * 2));", $script['code']);
    }

    public function test_a_final_statement_without_a_value_is_left_alone(): void
    {
        $script = $this->capture("echo 'hi';");

        $this->assertFalse($script['captured']);
        $this->assertSame("echo 'hi';", $script['code']);
    }

    public function test_trailing_comments_after_the_last_expression_are_kept(): void
    {
        $script = $this->capture("5;\n// done");

        $this->assertTrue($script['captured']);
        $this->assertSame("\$__nexusResult = (5);\n// done", $script['code']);
    }

    public function test_a_leading_php_tag_is_stripped(): void
    {
        $this->assertSame('$__nexusResult = (1);', $this->capture("<?php\n1;")['code']);
    }

    public function test_unparseable_code_passes_through_for_psysh_to_report(): void
    {
        $script = $this->capture('$x = ;');

        $this->assertFalse($script['captured']);
        $this->assertSame('$x = ;', $script['code']);
    }

    public function test_psysh_commands_pass_through_even_when_they_parse_as_php(): void
    {
        foreach (['ls', 'ls -al', 'wtf', 'doc User', 'show $user', 'help'] as $command) {
            $script = $this->capture($command);

            $this->assertFalse($script['captured'], $command);
            $this->assertSame($command, $script['code'], $command);
        }
    }

    public function test_php_that_starts_with_a_command_name_is_still_captured(): void
    {
        $this->assertTrue($this->capture('dump($user)')['captured']);
        $this->assertTrue($this->capture('$ls = 1')['captured']);
    }
}
