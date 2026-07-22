<?php

namespace App\Services;

/**
 * Bridges Nexus's serializer logic into a target project's tinker process.
 *
 * The value we want to render lives inside `php artisan tinker`, which boots the
 * *target* app and has no access to Nexus's classes. So we ship the logic as
 * source: the serializer file (see tinker_serializer.php) is base64-eval'd once
 * as a preamble, then a trailing emitter line prints the typed envelope of the
 * last-evaluated value ($_) between sentinels the runner extracts.
 *
 * All the injected lines are statements (guarded eval, try/catch, echo) rather
 * than bare expressions, so tinker's REPL prints no extra "= ..." result lines
 * into the raw view.
 */
class TinkerResultSerializer
{
    public const START = '__NEXUS_OUT_START__';

    public const END = '__NEXUS_OUT_END__';

    /**
     * PHP prepended to the piped stdin: defines the serializer (once) and turns
     * on query logging so runs can surface the SQL they triggered (Tier B4).
     */
    public function preamble(): string
    {
        $src = file_get_contents(__DIR__.'/tinker_serializer.php');
        // Drop the opening tag so the body can be eval'd as an expression.
        $src = preg_replace('/^\s*<\?php/', '', $src, 1);
        $b64 = base64_encode($src);

        return "if (! function_exists('nexus_serialize')) { eval(base64_decode('{$b64}')); }\n"
            ."try { \\DB::connection()->flushQueryLog(); \\DB::connection()->enableQueryLog(); } catch (\\Throwable \$e) {}\n";
    }

    /**
     * The trailing line: serialize the last-evaluated value ($_) and print the
     * JSON envelope wrapped in sentinels. Lenient JSON flags keep binary/invalid
     * UTF-8 from aborting the whole encode.
     */
    public function emitter(): string
    {
        $flags = 'JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES '
            .'| JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE';

        return 'echo "'.self::START.'".json_encode(nexus_envelope($_ ?? null), '.$flags.')."'.self::END.'";'."\n";
    }
}
