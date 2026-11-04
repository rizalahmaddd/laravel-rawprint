<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RawPrint\Laravel\Document\Writer;

final class WriterTest extends TestCase
{
    public function test_emits_escape_codes_in_order(): void
    {
        $writer = new Writer();
        $writer->reset()
            ->bold(true)
            ->bold(false)
            ->cpi(12)
            ->quality('nlq')
            ->condensed(true)
            ->ff()
            ->crlf();

        $this->assertSame(
            "\x1b\x40\x1b\x45\x01\x1b\x45\x00\x1b\x4d\x1b\x78\x01\x0f\x0c\r\n",
            $writer->bytes(),
        );
    }

    public function test_cpi_10_uses_pica(): void
    {
        $this->assertSame("\x1b\x50", (new Writer())->cpi(10)->bytes());
    }

    public function test_cpi_17_uses_pica_condensed(): void
    {
        $this->assertSame("\x1b\x50\x0f", (new Writer())->cpi(17)->bytes());
    }

    public function test_line_spacing_page_length_and_paper_feed(): void
    {
        $writer = new Writer();
        $writer->lineSpacing(36)->pageLength(66)->feedUnits(300);

        $this->assertSame(
            "\x1b\x33\x24\x1b\x43\x42\x1b\x4a\xff\x1b\x4a\x2d",
            $writer->bytes(),
        );
    }

    public function test_rejects_unknown_cpi(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Writer())->cpi(11);
    }

    public function test_rejects_unknown_quality(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Writer())->quality('high');
    }
}
