<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Document;

use InvalidArgumentException;

/**
 * Low level ESC/P byte writer. Every method appends to the same buffer so the
 * builder can compose a document without knowing which codes were emitted.
 */
final class Writer
{
    private string $buffer = '';

    public function raw(string $bytes): self
    {
        $this->buffer .= $bytes;

        return $this;
    }

    public function esc(int ...$bytes): self
    {
        $this->buffer .= "\x1b";

        foreach ($bytes as $byte) {
            $this->buffer .= chr($byte);
        }

        return $this;
    }

    public function byte(int $byte): self
    {
        $this->buffer .= chr($byte);

        return $this;
    }

    public function text(string $text): self
    {
        $this->buffer .= $text;

        return $this;
    }

    /** Carriage return + line feed. */
    public function crlf(): self
    {
        $this->buffer .= "\r\n";

        return $this;
    }

    public function lf(): self
    {
        $this->buffer .= "\n";

        return $this;
    }

    /** Form feed: go to the top of the next page. */
    public function ff(): self
    {
        $this->buffer .= "\f";

        return $this;
    }

    public function reset(): self
    {
        return $this->esc(0x40);
    }

    public function bold(bool $on): self
    {
        return $this->esc(0x45, $on ? 1 : 0);
    }

    public function doubleWidth(bool $on): self
    {
        return $this->esc(0x57, $on ? 1 : 0);
    }

    /** SI selects condensed, DC2 cancels it. */
    public function condensed(bool $on): self
    {
        return $this->byte($on ? 0x0F : 0x12);
    }

    public function cpi(int $cpi): self
    {
        return match ($cpi) {
            10 => $this->esc(0x50), // ESC P - pica
            12 => $this->esc(0x4D), // ESC M - elite
            17 => $this->esc(0x50)->byte(0x0F), // ESC P + SI - pica condensed
            default => throw new InvalidArgumentException('cpi must be 10, 12 or 17'),
        };
    }

    /** ESC 3 n sets the line height in 1/216 inch units. */
    public function lineSpacing(int $units216): self
    {
        return $this->esc(0x33, max(1, min(255, $units216)));
    }

    /** ESC C n sets the page length in whole lines. */
    public function pageLength(int $lines): self
    {
        return $this->esc(0x43, max(1, min(127, $lines)));
    }

    /** ESC J n advances the paper in 1/216 inch units. */
    public function feedUnits(int $units): self
    {
        while ($units > 0) {
            $step = min($units, 255);
            $this->esc(0x4A, $step);
            $units -= $step;
        }

        return $this;
    }

    public function quality(string $quality): self
    {
        return match ($quality) {
            'draft' => $this->esc(0x78, 0), // ESC x 0
            'nlq' => $this->esc(0x78, 1), // ESC x 1
            default => throw new InvalidArgumentException('quality must be draft or nlq'),
        };
    }

    public function bytes(): string
    {
        return $this->buffer;
    }

    public function length(): int
    {
        return strlen($this->buffer);
    }
}
