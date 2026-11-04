<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Document;

use InvalidArgumentException;

/**
 * Mirrors the paper profile stored by the agent so documents are paginated and
 * indented the same way the printer is calibrated.
 */
final class PaperProfile
{
    public function __construct(
        public readonly float $pageHeightMm = 279.4,
        public readonly int $topMarginLines = 3,
        public readonly int $leftMarginCols = 0,
        public readonly string $quality = 'draft',
        public readonly int $cpi = 12,
    ) {
        $this->validate();
    }

    public static function fromArray(array $profile): self
    {
        return new self(
            pageHeightMm: (float) ($profile['page_height_mm'] ?? 279.4),
            topMarginLines: (int) ($profile['top_margin_lines'] ?? 3),
            leftMarginCols: (int) ($profile['left_margin_cols'] ?? 0),
            quality: (string) ($profile['quality'] ?? 'draft'),
            cpi: (int) ($profile['cpi'] ?? 12),
        );
    }

    public function validate(): void
    {
        if ($this->pageHeightMm < 50 || $this->pageHeightMm > 600) {
            throw new InvalidArgumentException('page_height_mm must be between 50 and 600');
        }

        if ($this->topMarginLines < 0 || $this->topMarginLines > 20) {
            throw new InvalidArgumentException('top_margin_lines must be between 0 and 20');
        }

        if ($this->leftMarginCols < 0 || $this->leftMarginCols > 40) {
            throw new InvalidArgumentException('left_margin_cols must be between 0 and 40');
        }

        if (! in_array($this->quality, ['draft', 'nlq'], true)) {
            throw new InvalidArgumentException('quality must be draft or nlq');
        }

        if (! in_array($this->cpi, [10, 12, 17], true)) {
            throw new InvalidArgumentException('cpi must be 10, 12 or 17');
        }
    }

    public function linesPerPage(float $linesPerInch = 6.0): int
    {
        return max(1, $this->totalLines($linesPerInch) - $this->topMarginLines);
    }

    /** Page height in 1/216 inch units, the unit used by ESC J and ESC 3. */
    public function pageUnits(): int
    {
        return (int) round($this->pageHeightMm / 25.4 * 216);
    }

    /** Height of one text line in 1/216 inch units. */
    public function lineUnits(float $linesPerInch = 6.0): int
    {
        return max(1, (int) round(216 / max(0.1, $linesPerInch)));
    }

    public function totalLines(float $linesPerInch = 6.0): int
    {
        return max(1, intdiv($this->pageUnits(), $this->lineUnits($linesPerInch)));
    }

    /** Leftover fraction of a line that FF alone cannot advance. */
    public function remainderUnits(float $linesPerInch = 6.0): int
    {
        return $this->pageUnits() % $this->lineUnits($linesPerInch);
    }

    public function toArray(): array
    {
        return [
            'page_height_mm' => $this->pageHeightMm,
            'top_margin_lines' => $this->topMarginLines,
            'left_margin_cols' => $this->leftMarginCols,
            'quality' => $this->quality,
            'cpi' => $this->cpi,
        ];
    }
}
