<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui;

/**
 * A compact braille line chart — the wide, taller replacement for the inline
 * `▁▂▃▅▇` sparkline on the Metrics / Database / Cache dashboard tabs. Each
 * character cell packs a 2×4 dot grid (U+2800–U+28FF), so a chart W cells wide ×
 * H tall draws at 2·W × 4·H resolution: enough to read a real trend, not just a
 * vague wiggle.
 *
 * Pure and self-contained: a numeric series in, themed lines out. plot() does the
 * raw rasterising (no colour, no axis) and is the easiest thing to pin in a test;
 * render() wraps it with a header, a left y-axis gutter and a caption.
 */
class Chart
{
    /**
     * Braille dot bit per [subRow 0..3 top→bottom][subCol 0..1 left,right].
     * The low six dots are the classic 2×3 ordering; the bottom row (0x40/0x80)
     * was bolted on when Unicode extended braille to 2×4.
     */
    private const array DOTS = [
        [0x01, 0x08],
        [0x02, 0x10],
        [0x04, 0x20],
        [0x40, 0x80],
    ];

    /**
     * A labelled chart block: a `<label> · now <current>` header, a $height-row
     * braille plot with a left y-axis gutter (top tick = $max, bottom = $min), and
     * a muted caption (`<caption> · min … · max …`). Values scale to [$min,$max]
     * and clamp; an empty series renders the header + a "no data" line so the tab's
     * layout stays put. The plotted line is drawn in $colour.
     *
     * @param  array<int, float|null>  $series  oldest→newest
     * @return array<int, string>
     */
    public static function render(
        string $label,
        array $series,
        int $width,
        int $height,
        float $min,
        float $max,
        string $unit = '%',
        ?string $caption = null,
        Theme $colour = Theme::Primary,
    ): array {
        $values = array_values(array_filter($series, static fn (?float $value): bool => $value !== null));

        $maxLabel = self::tick($max, $unit);
        $minLabel = self::tick($min, $unit);
        $gutter = max(mb_strlen($maxLabel), mb_strlen($minLabel));

        $current = $values === [] ? '—' : self::tick($values[array_key_last($values)], $unit);
        $header = Theme::Text->bold('  ' . $label) . Theme::Muted->fg('   now ' . $current);

        if ($values === []) {
            return [$header, '  ' . Theme::Muted->fg(str_repeat(' ', $gutter) . ' │ no data')];
        }

        $rows = max(2, $height);
        // Prefix is `  ` (2) + the gutter + ` │` (2), so the plot gets the rest.
        $plot = self::plot($values, max(1, $width - $gutter - 4), $rows, $min, $max);

        $lines = [$header];

        foreach ($plot as $index => $row) {
            $tick = match ($index) {
                0 => $maxLabel,
                $rows - 1 => $minLabel,
                default => '',
            };

            $lines[] = '  ' . Theme::Muted->fg(self::pad($tick, $gutter) . ' │') . $colour->fg($row);
        }

        if ($caption !== null) {
            $stats = sprintf('min %s · max %s', self::tick(min($values), $unit), self::tick(max($values), $unit));
            $lines[] = '  ' . Theme::Muted->fg(str_repeat(' ', $gutter) . '   ' . $caption . ' · ' . $stats);
        }

        return $lines;
    }

    /**
     * Rasterise $series into a $rows×$cols braille grid (2·cols × 4·rows pixels),
     * connecting consecutive points vertically so it reads as a continuous line
     * rather than disconnected dots on steep slopes. Returns exactly $rows strings,
     * each $cols braille characters wide; an empty or flat ($max ≤ $min) series
     * yields blank (U+2800) rows so the caller's layout is unchanged.
     *
     * @param  array<int, float>  $series  oldest→newest
     * @return array<int, string>
     */
    public static function plot(array $series, int $cols, int $rows, float $min, float $max): array
    {
        $cols = max(1, $cols);
        $rows = max(1, $rows);

        /** @var array<int, array<int, int>> $grid */
        $grid = array_fill(0, $rows, array_fill(0, $cols, 0));

        $values = array_values($series);
        $count = count($values);

        if ($count > 0 && $max > $min) {
            $pixelsWide = $cols * 2;
            $pixelsHigh = $rows * 4;
            $previous = null;

            for ($x = 0; $x < $pixelsWide; $x++) {
                $index = $count === 1 ? 0 : (int) round($x / ($pixelsWide - 1) * ($count - 1));
                $clamped = max($min, min($max, $values[$index]));
                $y = (int) round(($clamped - $min) / ($max - $min) * ($pixelsHigh - 1));

                $low = $previous === null ? $y : min($previous, $y);
                $high = $previous === null ? $y : max($previous, $y);

                for ($pixel = $low; $pixel <= $high; $pixel++) {
                    self::set($grid, $x, $pixel, $rows);
                }

                $previous = $y;
            }
        }

        return array_map(self::rowToString(...), $grid);
    }

    /**
     * Light one braille dot. $x is a pixel column (0 at the left); $y is a pixel
     * row measured from the bottom (0 = lowest), so a larger value sits higher.
     *
     * @param  array<int, array<int, int>>  $grid
     */
    private static function set(array &$grid, int $x, int $y, int $rows): void
    {
        $fromTop = ($rows * 4 - 1) - $y;
        $grid[intdiv($fromTop, 4)][intdiv($x, 2)] |= self::DOTS[$fromTop % 4][$x % 2];
    }

    /**
     * @param  array<int, int>  $row
     */
    private static function rowToString(array $row): string
    {
        return implode('', array_map(static fn (int $cell): string => mb_chr(0x2800 + $cell), $row));
    }

    /**
     * A sensible upper bound for an unbounded metric (requests, connections,
     * latency, memory): the series max rounded up, floored at 1 so an all-zero or
     * empty series still scales rather than collapsing to a zero range.
     *
     * @param  array<int, float>  $series
     */
    public static function ceiling(array $series): float
    {
        return $series === [] ? 1.0 : max(1.0, ceil(max($series)));
    }

    /** A right-aligned y-axis tick, padded to the gutter width. */
    private static function pad(string $label, int $width): string
    {
        return str_pad($label, $width, ' ', STR_PAD_LEFT);
    }

    /** Format a tick / reading: whole numbers above 100, one decimal below, + unit. */
    private static function tick(float $value, string $unit): string
    {
        $rounded = $value >= 100 ? (string) (int) round($value) : (string) round($value, 1);
        $rounded = str_contains($rounded, '.') ? rtrim(rtrim($rounded, '0'), '.') : $rounded;

        return $rounded . $unit;
    }
}
