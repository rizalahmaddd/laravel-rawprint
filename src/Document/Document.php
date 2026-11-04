<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Document;

use InvalidArgumentException;

/**
 * Builds an ESC/P document line by line. Long lines wrap, the left and top
 * margins from the paper profile are applied, and a form feed is emitted when
 * a page is full.
 */
final class Document
{
    private readonly Writer $writer;

    private readonly PaperProfile $profile;

    private readonly int $lineWidth;

    private readonly int $linesPerPage;

    private readonly float $linesPerInch;

    private int $line = 0;

    private int $page = 1;

    private bool $wrote = false;

    private bool $started = false;

    private string $align = 'left';

    private bool $bold = false;

    private bool $doubleWidth = false;

    private bool $condensed = false;

    private ?bool $appliedBold = null;

    private ?bool $appliedDoubleWidth = null;

    private ?bool $appliedCondensed = null;

    public function __construct(
        ?PaperProfile $profile = null,
        int $lineWidth = 0,
        float $linesPerInch = 6.0,
        float $paperWidthMm = 203.2,
    ) {
        $this->profile = $profile ?? new PaperProfile();
        $this->lineWidth = $lineWidth > 0
            ? $lineWidth
            : max(20, (int) floor($paperWidthMm / 25.4 * $this->profile->cpi));
        $this->linesPerInch = max(0.1, $linesPerInch);
        $this->linesPerPage = $this->profile->linesPerPage($this->linesPerInch);
        $this->writer = new Writer();
    }

    public static function make(
        ?PaperProfile $profile = null,
        int $lineWidth = 0,
        float $linesPerInch = 6.0,
        float $paperWidthMm = 203.2,
    ): self {
        return new self($profile ?? new PaperProfile(), $lineWidth, $linesPerInch, $paperWidthMm);
    }

    public function bold(bool $on = true): self
    {
        $this->bold = $on;

        return $this;
    }

    public function doubleWidth(bool $on = true): self
    {
        $this->doubleWidth = $on;

        return $this;
    }

    public function condensed(bool $on = true): self
    {
        $this->condensed = $on;

        return $this;
    }

    public function align(string $align): self
    {
        if (! in_array($align, ['left', 'center', 'right'], true)) {
            throw new InvalidArgumentException('align must be left, center or right');
        }

        $this->align = $align;

        return $this;
    }

    /** Writes a line. Long text wraps at word boundaries unless $wrap is false. */
    public function line(string $text = '', bool $wrap = true): self
    {
        $text = $this->normalize($text);

        if (str_contains($text, "\n")) {
            foreach (explode("\n", $text) as $line) {
                $this->line($line, $wrap);
            }

            return $this;
        }

        $chunks = $wrap ? $this->wrap($text, $this->contentWidth()) : [$text];

        foreach ($chunks as $chunk) {
            $this->writeLine($chunk);
        }

        return $this;
    }

    /** Writes a plain text block, one line per newline, wrapping long lines. */
    public function text(string $text): self
    {
        return $this->line($text);
    }

    public function lines(iterable $lines): self
    {
        foreach ($lines as $line) {
            $this->line((string) $line);
        }

        return $this;
    }

    public function blank(int $lines = 1): self
    {
        for ($i = 0; $i < max(0, $lines); $i++) {
            $this->writeLine('');
        }

        return $this;
    }

    /**
     * Writes fixed width cells. Missing widths are distributed evenly.
     */
    public function row(array $cells, array $widths = [], string $separator = ''): self
    {
        $cells = array_values($cells);
        $count = max(1, count($cells));

        if ($widths === []) {
            $available = $this->contentWidth() - strlen($separator) * ($count - 1);
            $widths = array_fill(0, $count, max(1, intdiv($available, $count)));
        }

        $widths = array_values($widths);
        $out = [];

        foreach ($cells as $i => $cell) {
            $width = (int) ($widths[$i] ?? $widths[count($widths) - 1]);
            $out[] = str_pad($this->truncate((string) $cell, $width), max(0, $width));
        }

        return $this->line(implode($separator, $out), false);
    }

    /** Emits a form feed and starts a new page. No-op on an empty document. */
    public function pageBreak(): self
    {
        $this->start();

        if (! $this->wrote) {
            return $this;
        }

        $this->endPage();

        return $this;
    }

    public function bytes(): string
    {
        $this->start();

        return $this->writer->bytes();
    }

    public function base64(): string
    {
        return base64_encode($this->bytes());
    }

    public function pageCount(): int
    {
        return $this->wrote ? $this->page : 0;
    }

    public function width(): int
    {
        return $this->contentWidth();
    }

    public function profile(): PaperProfile
    {
        return $this->profile;
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->writer->reset();
        $this->writer->quality($this->profile->quality);
        $this->writer->lineSpacing($this->profile->lineUnits($this->linesPerInch));

        if ($this->profile->remainderUnits($this->linesPerInch) === 0
            && $this->profile->totalLines($this->linesPerInch) < 128) {
            $this->writer->pageLength($this->profile->totalLines($this->linesPerInch));
        }

        $this->writer->cpi($this->profile->cpi);
        $this->appliedBold = false;
        $this->appliedDoubleWidth = false;
        $this->appliedCondensed = $this->profile->cpi === 17;
        $this->emitTopMargin();
    }

    private function emitTopMargin(): void
    {
        for ($i = 0; $i < $this->profile->topMarginLines; $i++) {
            $this->writer->crlf();
        }
    }

    private function ensurePage(): void
    {
        $this->start();

        if ($this->line < $this->linesPerPage) {
            return;
        }

        $this->endPage();
    }

    /**
     * Ends the current page at the perforation. A whole number of lines can use
     * FF; a fractional page needs ESC J so the paper does not drift.
     */
    private function endPage(): void
    {
        $lineUnits = $this->profile->lineUnits($this->linesPerInch);
        $total = $this->profile->totalLines($this->linesPerInch);
        $remainder = $this->profile->remainderUnits($this->linesPerInch);
        $used = $this->profile->topMarginLines + $this->line;
        $left = max(0, $total - $used) * $lineUnits + $remainder;

        if ($left > 0) {
            $this->writer->feedUnits($left);
        } else {
            $this->writer->ff();
        }

        $this->page++;
        $this->line = 0;
        $this->emitTopMargin();
    }

    private function writeLine(string $text): void
    {
        $this->ensurePage();
        $this->syncStyle();

        $text = $this->applyAlign($this->truncate($text, $this->contentWidth()));

        $this->writer
            ->text(str_repeat(' ', $this->profile->leftMarginCols).$text)
            ->crlf();

        $this->line++;
        $this->wrote = true;
    }

    private function syncStyle(): void
    {
        if ($this->appliedBold !== $this->bold) {
            $this->writer->bold($this->bold);
            $this->appliedBold = $this->bold;
        }

        if ($this->appliedDoubleWidth !== $this->doubleWidth) {
            $this->writer->doubleWidth($this->doubleWidth);
            $this->appliedDoubleWidth = $this->doubleWidth;
        }

        if ($this->appliedCondensed !== $this->condensed) {
            $this->writer->condensed($this->condensed);
            $this->appliedCondensed = $this->condensed;
        }
    }

    private function contentWidth(): int
    {
        $width = max(1, $this->lineWidth - $this->profile->leftMarginCols);

        if ($this->doubleWidth) {
            $width = max(1, intdiv($width, 2));
        }

        return $width;
    }

    private function applyAlign(string $text): string
    {
        $width = $this->contentWidth();
        $length = strlen($text);

        return match ($this->align) {
            'right' => str_repeat(' ', max(0, $width - $length)).$text,
            'center' => str_repeat(' ', max(0, intdiv($width - $length, 2))).$text,
            default => $text,
        };
    }

    private function truncate(string $text, int $width): string
    {
        return $width > 0 ? substr($text, 0, $width) : '';
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\t", '        ', $text);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
    }

    /** @return list<string> */
    private function wrap(string $text, int $width): array
    {
        if ($width <= 0 || strlen($text) <= $width) {
            return [$text];
        }

        $out = [];
        $line = '';

        foreach (explode(' ', $text) as $word) {
            if ($line === '') {
                while (strlen($word) > $width) {
                    $out[] = substr($word, 0, $width);
                    $word = substr($word, $width);
                }

                $line = $word;

                continue;
            }

            if (strlen($line) + 1 + strlen($word) <= $width) {
                $line .= ' '.$word;

                continue;
            }

            $out[] = $line;

            while (strlen($word) > $width) {
                $out[] = substr($word, 0, $width);
                $word = substr($word, $width);
            }

            $line = $word;
        }

        $out[] = $line;

        return $out;
    }
}
