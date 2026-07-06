<?php

namespace Fain182\ImapPolyfill\Tests\Unit;

use Fain182\ImapPolyfill\BodyStructure;
use PHPUnit\Framework\TestCase;

class BodyStructureTest extends TestCase
{
    public function test_builds_a_single_text_part(): void
    {
        $parsed = ['text', 'plain', ['charset', 'us-ascii'], null, null, '7BIT', 10, 1, null, null, null];

        $result = BodyStructure::build($parsed);

        $this->assertSame(0, $result->type); // TYPETEXT
        $this->assertSame(0, $result->encoding); // ENC7BIT
        $this->assertSame(1, $result->ifsubtype);
        $this->assertSame('PLAIN', $result->subtype); // ext-imap uppercases the subtype
        $this->assertSame(0, $result->ifid);
        $this->assertObjectNotHasProperty('id', $result);
        $this->assertSame(0, $result->ifdescription);
        $this->assertSame(10, $result->bytes);
        $this->assertSame(1, $result->lines);
        $this->assertSame(1, $result->ifparameters);
        $this->assertCount(1, $result->parameters);
        $this->assertSame('charset', $result->parameters[0]->attribute);
        $this->assertSame('us-ascii', $result->parameters[0]->value);
        $this->assertSame(0, $result->ifdisposition);
        $this->assertObjectNotHasProperty('parts', $result);
    }

    public function test_builds_a_multipart_with_nested_parts_and_disposition(): void
    {
        $parsed = [
            ['text', 'plain', ['charset', 'us-ascii'], null, null, '7BIT', 10, 1, null, null, null],
            ['APPLICATION', 'OCTET-STREAM', ['name', 'test.bin'], null, null, 'base64', 4, null, ['attachment', ['filename', 'test.bin']], null],
            'mixed',
            ['boundary', 'BOUND1'],
            null,
            null,
        ];

        $result = BodyStructure::build($parsed);

        $this->assertSame(1, $result->type); // TYPEMULTIPART
        $this->assertSame(0, $result->encoding);
        $this->assertSame(0, $result->ifid);
        $this->assertSame(0, $result->ifdescription);
        $this->assertSame('MIXED', $result->subtype);
        $this->assertSame(1, $result->ifparameters);
        $this->assertSame('boundary', $result->parameters[0]->attribute);
        $this->assertCount(2, $result->parts);

        $textPart = $result->parts[0];
        $this->assertSame(0, $textPart->type);
        $this->assertSame('PLAIN', $textPart->subtype);

        $attachmentPart = $result->parts[1];
        $this->assertSame(3, $attachmentPart->type); // TYPEAPPLICATION
        $this->assertSame(3, $attachmentPart->encoding); // ENCBASE64
        $this->assertSame(4, $attachmentPart->bytes);
        $this->assertObjectNotHasProperty('lines', $attachmentPart);
        $this->assertSame(1, $attachmentPart->ifdisposition);
        $this->assertSame('attachment', $attachmentPart->disposition);
        $this->assertSame(1, $attachmentPart->ifdparameters);
        $this->assertSame('filename', $attachmentPart->dparameters[0]->attribute);
        $this->assertSame('test.bin', $attachmentPart->dparameters[0]->value);
    }
}
