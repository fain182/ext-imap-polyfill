<?php

namespace ImapPolyfill\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * imap_mail() hands the message to sendmail_path, which is PHP_INI_SYSTEM —
 * it can't be changed at runtime. Anything that reaches delivery therefore
 * runs in a php subprocess whose sendmail_path captures stdin to a file,
 * which also guarantees no test can ever hand mail to a real MTA. Both the
 * real extension and this polyfill honor sendmail_path, so the capture
 * works identically under the parity job — and since the polyfill ports
 * _php_imap_mail()'s pipe verbatim, the captured stream can be asserted
 * byte for byte.
 */
class ImapMailTest extends TestCase
{
    public function test_rejects_an_empty_to(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_mail(): Argument #1 ($to) cannot be empty');

        imap_mail('', 'Subject', 'body');
    }

    public function test_rejects_an_empty_subject(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('imap_mail(): Argument #2 ($subject) cannot be empty');

        imap_mail('dest@example.test', '', 'body');
    }

    public function test_delivers_all_recipient_headers_and_the_body(): void
    {
        [$output, $captured] = $this->sendInSubprocess(
            "imap_mail('dest@example.test', 'A subject', 'the body',"
            ."'X-Custom: 1', 'copy@example.test', 'blind@example.test', 'sender@example.test')"
        );

        $this->assertStringContainsString('RESULT:true', $output);
        $this->assertSame(
            "From: sender@example.test\n"
            ."To: dest@example.test\n"
            ."Cc: copy@example.test\n"
            ."Bcc: blind@example.test\n"
            ."Subject: A subject\n"
            ."X-Custom: 1\n"
            ."\n"
            ."the body\n",
            $captured
        );
    }

    public function test_omits_the_optional_headers_when_not_given(): void
    {
        [$output, $captured] = $this->sendInSubprocess(
            "imap_mail('dest@example.test', 'Bare', 'hi')"
        );

        $this->assertStringContainsString('RESULT:true', $output);
        $this->assertSame(
            "To: dest@example.test\n"
            ."Subject: Bare\n"
            ."\n"
            ."hi\n",
            $captured
        );
    }

    public function test_warns_but_still_delivers_when_the_message_is_empty(): void
    {
        [$output, $captured] = $this->sendInSubprocess(
            "imap_mail('dest@example.test', 'Empty', '')"
        );

        $this->assertStringContainsString('No message string in mail command', $output);
        $this->assertStringContainsString('RESULT:true', $output);
        $this->assertSame(
            "To: dest@example.test\n"
            ."Subject: Empty\n"
            ."\n"
            ."\n",
            $captured
        );
    }

    /**
     * Runs $call (an expression, no trailing semicolon) in a fresh php
     * process whose sendmail_path writes the composed message to a capture
     * file; returns the process output (with RESULT:<bool> appended) and
     * the captured message.
     *
     * @return array{string, string}
     */
    private function sendInSubprocess(string $call): array
    {
        $captureFile = tempnam(sys_get_temp_dir(), 'imap_mail_');
        $this->assertIsString($captureFile);

        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        $code = 'require '.var_export($autoload, true).'; '
            .'$result = '.rtrim($call, ';').'; '
            .'echo "RESULT:", var_export($result, true);';
        $command = sprintf(
            '%s -d sendmail_path=%s -d display_errors=1 -d error_reporting=E_ALL -r %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg('cat > '.$captureFile),
            escapeshellarg($code)
        );

        try {
            $output = (string) shell_exec($command);

            return [$output, (string) file_get_contents($captureFile)];
        } finally {
            @unlink($captureFile);
        }
    }
}
