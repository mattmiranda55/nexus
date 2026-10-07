<?php

namespace Tests\Unit;

use App\Services\Mail\MimeParser;
use PHPUnit\Framework\TestCase;

class MimeParserTest extends TestCase
{
    public function test_a_multipart_message_yields_decoded_bodies_headers_and_attachments(): void
    {
        $raw = implode("\r\n", [
            'From: =?UTF-8?B?QWRhIEzDtnZlbGFjZQ==?= <ada@example.test>',
            'To: "Smith, Jo" <jo@example.test>, bob@example.test',
            'Subject: =?utf-8?Q?Caf=C3=A9_receipt?=',
            'Date: Tue, 07 Oct 2026 10:00:00 +0000',
            'Content-Type: multipart/mixed; boundary="outer"',
            '',
            'preamble',
            '--outer',
            'Content-Type: multipart/alternative; boundary=inner',
            '',
            '--inner',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'Caf=C3=A9 total: =E2=82=AC5',
            '--inner',
            'Content-Type: text/html; charset=iso-8859-1',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode("<p>Caf\xE9</p>"),
            '--inner--',
            '--outer',
            'Content-Type: application/pdf; name="receipt.pdf"',
            'Content-Disposition: attachment; filename="receipt.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('%PDF-1.4 fake'),
            '--outer--',
            '',
        ]);

        $parsed = (new MimeParser)->parse($raw);

        $this->assertSame('Café receipt', $parsed['headers']['subject'][0]);
        $this->assertSame('Café total: €5', $parsed['text']);
        $this->assertSame('<p>Café</p>', $parsed['html']);
        $this->assertSame([['name' => 'receipt.pdf', 'contentType' => 'application/pdf', 'size' => 13]], $parsed['attachments']);

        $this->assertSame(['name' => 'Ada Lövelace', 'address' => 'ada@example.test'], MimeParser::address($parsed['headers']['from'][0]));
        $this->assertSame([
            ['name' => 'Smith, Jo', 'address' => 'jo@example.test'],
            ['name' => '', 'address' => 'bob@example.test'],
        ], MimeParser::addressList($parsed['headers']['to'][0]));
    }

    public function test_a_plain_message_is_its_own_text_body(): void
    {
        $parsed = (new MimeParser)->parse("Subject: Hi\n\nJust text.\n");

        $this->assertSame('Just text.', $parsed['text']);
        $this->assertNull($parsed['html']);
    }

    public function test_runaway_nesting_is_cut_off(): void
    {
        $raw = 'Content-Type: multipart/mixed; boundary=b0'."\n\n";
        for ($i = 0; $i < 20; $i++) {
            $raw .= "--b{$i}\nContent-Type: multipart/mixed; boundary=b".($i + 1)."\n\n";
        }

        $this->assertNull((new MimeParser)->parse($raw)['text']);
    }
}
