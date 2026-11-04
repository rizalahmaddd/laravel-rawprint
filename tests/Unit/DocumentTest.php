<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RawPrint\Laravel\Document\Document;
use RawPrint\Laravel\Document\PaperProfile;

final class DocumentTest extends TestCase
{
    public function test_applies_top_and_left_margins(): void
    {
        $document = Document::make(
            new PaperProfile(cpi: 10, topMarginLines: 2, leftMarginCols: 3, quality: 'draft'),
            lineWidth: 20,
        );

        $document->line('A');
        $bytes = $document->bytes();

        $this->assertStringStartsWith("\x1b\x40\x1b\x78\x00\x1b\x33\x24\x1b\x43\x42\x1b\x50\r\n\r\n", $bytes);
        $this->assertStringEndsWith("   A\r\n", $bytes);
    }

    public function test_form_feed_is_emitted_when_a_page_is_full(): void
    {
        // 279.4 mm at 6 lines per inch is 66 lines, minus 3 top margin lines.
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 3), lineWidth: 40);

        for ($i = 0; $i < 63; $i++) {
            $document->line('X');
        }

        $this->assertSame(0, substr_count($document->bytes(), "\f"));
        $this->assertSame(1, $document->pageCount());

        $document->line('X');

        $this->assertSame(1, substr_count($document->bytes(), "\f"));
        $this->assertSame(2, $document->pageCount());
    }

    public function test_fractional_page_height_feeds_the_remainder_with_esc_j(): void
    {
        // 100 mm is 850/216 inch: 23 whole lines plus 22 units left over.
        $document = Document::make(new PaperProfile(pageHeightMm: 100, cpi: 10, topMarginLines: 0), lineWidth: 20);

        for ($i = 0; $i < 24; $i++) {
            $document->line('X');
        }

        $this->assertSame(1, substr_count($document->bytes(), "\x1b\x4a\x16"));
        $this->assertSame(0, substr_count($document->bytes(), "\f"));
        $this->assertSame(2, $document->pageCount());
    }

    public function test_right_alignment(): void
    {
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 0), lineWidth: 10);
        $document->align('right')->line('AB');

        $this->assertStringEndsWith(str_repeat(' ', 8)."AB\r\n", $document->bytes());
    }

    public function test_center_alignment(): void
    {
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 0), lineWidth: 10);
        $document->align('center')->line('AB');

        $this->assertStringEndsWith(str_repeat(' ', 4)."AB\r\n", $document->bytes());
    }

    public function test_columns_are_padded_and_truncated(): void
    {
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 0), lineWidth: 20);
        $document->row(['Item', 'Qty'], [10, 5], '|');

        $this->assertStringEndsWith("Item      |Qty  \r\n", $document->bytes());
    }

    public function test_long_text_wraps_at_word_boundaries(): void
    {
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 0), lineWidth: 10);
        $document->line('hello world again');

        $this->assertSame(3, substr_count($document->bytes(), "\r\n"));
        $this->assertStringEndsWith("again\r\n", $document->bytes());
    }

    public function test_text_wraps_and_keeps_blank_lines(): void
    {
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 0), lineWidth: 5);
        $document->text("abcdefgh\n\nx");

        $this->assertSame(4, substr_count($document->bytes(), "\r\n"));
        $this->assertStringEndsWith("x\r\n", $document->bytes());
    }

    public function test_line_splits_embedded_newlines(): void
    {
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 0), lineWidth: 20);
        $document->line("A\nB");

        $this->assertSame(2, substr_count($document->bytes(), "\r\n"));
        $this->assertStringEndsWith("B\r\n", $document->bytes());
    }

    public function test_bold_is_switched_on_and_off(): void
    {
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 0));
        $document->bold()->line('A')->bold(false)->line('B');

        $bytes = $document->bytes();

        $this->assertSame(1, substr_count($bytes, "\x1b\x45\x01"));
        $this->assertSame(1, substr_count($bytes, "\x1b\x45\x00"));
    }

    public function test_double_width_halves_the_content_width(): void
    {
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 0), lineWidth: 10);
        $document->doubleWidth()->line('1234567890');

        $this->assertStringEndsWith("12345\r\n67890\r\n", $document->bytes());
    }

    public function test_page_break_on_empty_document_is_a_no_op(): void
    {
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 0));
        $document->pageBreak();

        $this->assertSame(0, substr_count($document->bytes(), "\f"));
        $this->assertSame(0, $document->pageCount());
    }

    public function test_base64_matches_bytes(): void
    {
        $document = Document::make(new PaperProfile(cpi: 10, topMarginLines: 0));
        $document->line('Hello');

        $this->assertSame($document->bytes(), base64_decode($document->base64(), true));
    }

    public function test_profile_rejects_out_of_range_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaperProfile(pageHeightMm: 10);
    }
}
