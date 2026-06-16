<?php

declare(strict_types=1);

use Codinglabs\Yolo\Tui\Chart;

/** A braille row is "blank" when every cell is the empty pattern (U+2800). */
function isBlankBraille(string $row): bool
{
    return str_replace("\u{2800}", '', $row) === '';
}

it('plots a series into exactly the requested rows and columns', function (): void {
    $rows = Chart::plot([0.0, 50.0, 100.0], 10, 4, 0, 100);

    expect($rows)->toHaveCount(4);

    foreach ($rows as $row) {
        expect(mb_strlen($row))->toBe(10);
    }
});

it('renders an empty series as blank braille rows', function (): void {
    $rows = Chart::plot([], 8, 3, 0, 100);

    expect($rows)->toHaveCount(3)
        ->and(array_filter($rows, fn (string $row): bool => ! isBlankBraille($row)))->toBe([]);
});

it('lights the top row for a maxed-out series and the bottom row for a floored one', function (): void {
    $high = Chart::plot([100.0, 100.0, 100.0], 12, 4, 0, 100);
    $low = Chart::plot([0.0, 0.0, 0.0], 12, 4, 0, 100);

    expect(isBlankBraille($high[0]))->toBeFalse()      // top row lit
        ->and(isBlankBraille($high[3]))->toBeTrue()    // bottom row blank
        ->and(isBlankBraille($low[3]))->toBeFalse()    // bottom row lit
        ->and(isBlankBraille($low[0]))->toBeTrue();    // top row blank
});

it('clamps values outside the range rather than overflowing the grid', function (): void {
    // 250 on a 0–100 scale must land on the top row, just like 100.
    $rows = Chart::plot([250.0, 250.0], 12, 4, 0, 100);

    expect(isBlankBraille($rows[0]))->toBeFalse()
        ->and(isBlankBraille($rows[3]))->toBeTrue();
});

it('renders a labelled chart block with a header, gutter ticks and a caption', function (): void {
    $lines = Chart::render('web · CPU', [12.0, 58.0, 94.0], 60, 5, 0, 100, '%', 'last 60m');

    expect($lines)->toHaveCount(1 + 5 + 1)          // header + body + caption
        ->and($lines[0])->toContain('web · CPU')->toContain('now 94%')
        ->and($lines[1])->toContain('100%')         // top gutter tick = max
        ->and($lines[5])->toContain('0%')           // bottom gutter tick = min
        ->and($lines[6])->toContain('last 60m')->toContain('min 12%')->toContain('max 94%');
});

it('renders a no-data frame for an empty series', function (): void {
    $lines = Chart::render('queue · CPU', [], 40, 5, 0, 100, '%', 'last 60m');

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('queue · CPU')->toContain('now —')
        ->and($lines[1])->toContain('no data');
});

it('scales an unbounded metric to a floor of 1, rounding the max up', function (): void {
    // The floor-at-1 keeps an all-zero/empty series from collapsing to a zero
    // range (which would blank the chart); a real max rounds up to a whole tick.
    expect(Chart::ceiling([]))->toBe(1.0)
        ->and(Chart::ceiling([0.0, 0.0]))->toBe(1.0)
        ->and(Chart::ceiling([0.3, 0.7]))->toBe(1.0)
        ->and(Chart::ceiling([5.0, 12.4]))->toBe(13.0);
});
