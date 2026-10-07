<?php

namespace App\Services\Mail;

/**
 * Just enough RFC 822/MIME parsing to show a caught message: headers (with
 * encoded words decoded), the first text and HTML bodies, and attachment
 * names. For catchers whose API hands back undecoded MIME (MailHog).
 *
 * @phpstan-type Parsed array{headers: array<string, list<string>>, html: ?string, text: ?string, attachments: list<array{name: string, contentType: string, size: int}>}
 */
class MimeParser
{
    /** Nested multiparts deeper than this are ignored (malformed or hostile). */
    private const MAX_DEPTH = 8;

    /** @return Parsed */
    public function parse(string $raw): array
    {
        $result = ['headers' => [], 'html' => null, 'text' => null, 'attachments' => []];
        [$headers, $body] = $this->split($raw);
        $result['headers'] = $headers;
        $this->walk($headers, $body, $result, 0);

        return $result;
    }

    /** "Ada <ada@x.test>" / "ada@x.test" / '"Ada, L." <ada@x.test>' → {name, address}. */
    public static function address(string $value): array
    {
        $value = trim(self::decodeHeader($value));

        if (preg_match('/^(.*?)\s*<([^>]*)>\s*$/', $value, $m)) {
            return ['name' => trim($m[1], " \"'"), 'address' => trim($m[2])];
        }

        return ['name' => '', 'address' => $value];
    }

    /** @return list<array{name: string, address: string}> */
    public static function addressList(string $value): array
    {
        // Split on commas outside quotes and angle brackets.
        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)(?![^<]*>)/', $value) ?: [];

        return array_values(array_map(
            fn ($p) => self::address($p),
            array_filter($parts, fn ($p) => trim($p) !== ''),
        ));
    }

    /** RFC 2047 encoded words (=?utf-8?B?…?=) to UTF-8. */
    public static function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = function_exists('iconv_mime_decode')
            ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')
            : false;

        return $decoded !== false ? $decoded : mb_decode_mimeheader($value);
    }

    /**
     * Split a part into headers (lower-cased names, unfolded, decoded) and body.
     *
     * @return array{0: array<string, list<string>>, 1: string}
     */
    private function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        [$head, $body] = $pos === false ? [$raw, ''] : [substr($raw, 0, $pos), substr($raw, $pos + 2)];

        $headers = [];
        $head = preg_replace('/\n[ \t]+/', ' ', $head) ?? $head; // unfold
        foreach (explode("\n", $head) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))][] = self::decodeHeader(trim($value));
        }

        return [$headers, $body];
    }

    /** @param  Parsed  $result */
    private function walk(array $headers, string $body, array &$result, int $depth): void
    {
        [$type, $params] = $this->contentType($headers['content-type'][0] ?? 'text/plain');

        if (str_starts_with($type, 'multipart/')) {
            if ($depth >= self::MAX_DEPTH || ! isset($params['boundary'])) {
                return;
            }

            $boundary = preg_quote($params['boundary'], '/');
            $sections = preg_split('/^--'.$boundary.'(?:--)?[ \t]*$/m', $body) ?: [];
            // First is the preamble, last the epilogue.
            foreach (array_slice($sections, 1, -1) as $section) {
                [$h, $b] = $this->split(ltrim($section, "\n"));
                $this->walk($h, $b, $result, $depth + 1);
            }

            return;
        }

        $content = $this->decodeBody($body, $headers['content-transfer-encoding'][0] ?? '', $params['charset'] ?? null, $type);
        $disposition = strtolower($headers['content-disposition'][0] ?? '');
        $name = $this->filename($headers, $params);

        if (str_starts_with($disposition, 'attachment') || ($name !== null && ! str_starts_with($type, 'text/'))) {
            $result['attachments'][] = ['name' => $name ?? 'attachment', 'contentType' => $type, 'size' => strlen($content)];
        } elseif ($type === 'text/html' && $result['html'] === null) {
            $result['html'] = $content;
        } elseif ($type === 'text/plain' && $result['text'] === null) {
            $result['text'] = $content;
        }
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function contentType(string $value): array
    {
        $pieces = explode(';', $value);
        $params = [];
        foreach (array_slice($pieces, 1) as $piece) {
            if (str_contains($piece, '=')) {
                [$k, $v] = explode('=', $piece, 2);
                $params[strtolower(trim($k))] = trim(trim($v), '"');
            }
        }

        return [strtolower(trim($pieces[0])), $params];
    }

    private function filename(array $headers, array $params): ?string
    {
        if (preg_match('/filename\*?="?([^";]+)"?/i', $headers['content-disposition'][0] ?? '', $m)) {
            return $m[1];
        }

        return $params['name'] ?? null;
    }

    private function decodeBody(string $body, string $encoding, ?string $charset, string $type): string
    {
        $decoded = match (strtolower(trim($encoding))) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body) ?? '', false) ?: '',
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };

        if (! str_starts_with($type, 'text/')) {
            return $decoded;
        }

        $charset = strtoupper($charset ?? 'UTF-8');
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            $decoded = is_string($converted) ? $converted : $decoded;
        }

        return rtrim($decoded, "\n");
    }
}
