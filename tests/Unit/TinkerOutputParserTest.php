<?php

namespace Tests\Unit;

use App\Services\TinkerOutputParser;
use PHPUnit\Framework\TestCase;

class TinkerOutputParserTest extends TestCase
{
    private function parse(string $output, array $input): string
    {
        return (new TinkerOutputParser)->parse($output, $input);
    }

    public function test_echoed_input_is_dropped_and_echo_output_is_kept(): void
    {
        // Real shape: all input echoed first, output starts on the last prompt.
        $output = "> \$a = 1;\n\n> echo 'hello';\n> hello";

        $this->assertSame('hello', $this->parse($output, ['$a = 1;', "echo 'hello';"]));
    }

    public function test_output_that_looks_like_a_prompt_is_kept(): void
    {
        $output = "> echo \"> not input\";\n> > not input";

        $this->assertSame('> not input', $this->parse($output, ['echo "> not input";']));
    }

    public function test_long_input_lines_echoed_truncated_with_a_redraw_are_dropped(): void
    {
        $long = 'echo "'.str_repeat('A', 120).'";';
        $output = "> \r<".substr($long, -40)."\n> ".str_repeat('A', 120);

        $this->assertSame(str_repeat('A', 120), $this->parse($output, [$long]));
    }

    public function test_multi_line_results_keep_their_indentation(): void
    {
        $output = "> \$x;\n> = [\n    10,\n    20,\n  ]";

        $this->assertSame("[\n  10,\n  20,\n]", $this->parse($output, ['$x;']));
    }

    public function test_dump_output_and_the_result_both_survive(): void
    {
        $output = "> dump('hi'); \$r = (7);\n> \$r;\n> \"hi\" // eval()'d code:1\n\n= 7";

        $this->assertSame("\"hi\" // eval()'d code:1\n\n7", $this->parse($output, ["dump('hi'); \$r = (7);", '$r;']));
    }

    public function test_continuation_prompts_are_recognised_as_echo(): void
    {
        $output = "> if (true) {\n.   \$y = 1;\n. }\n> = null";

        $this->assertSame('null', $this->parse($output, ['if (true) {', '  $y = 1;', '}']));
    }

    public function test_crlf_output_parses_like_lf(): void
    {
        $this->assertSame('5', $this->parse("> 5;\r\n> = 5\r\n", ['5;']));
    }
}
