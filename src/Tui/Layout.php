<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui;

/**
 * Fits a frame to an exact terminal height: fixed chrome above and below a body.
 * The body is padded with blank rows up to its budget — so the footer always
 * pins to the last row instead of floating wherever the body happens to end — or
 * truncated when it would overflow. The result is always exactly $height rows, so
 * Screen's in-place repaint never scrolls the alternate buffer.
 */
class Layout
{
    /**
     * @param  array<int, string>  $top  fixed chrome above the body
     * @param  array<int, string>  $body  the panel body (clipped/padded to its budget)
     * @param  array<int, string>  $bottom  fixed chrome below the body (footer last)
     * @return array<int, string> exactly max(0, $height) rows
     */
    public static function fit(array $top, array $body, array $bottom, int $height): array
    {
        $top = array_values($top);
        $bottom = array_values($bottom);

        $budget = self::bodyBudget(count($top), count($bottom), $height);

        // Shape the body to exactly its budget: truncate when long, pad with blank
        // rows when short. The pad is what pins the footer to the bottom.
        $body = array_pad(array_slice(array_values($body), 0, $budget), $budget, '');

        // When the chrome alone overflows a very short terminal there's no body
        // budget left; clip the whole frame to the height so we still return
        // exactly $height rows (the top chrome wins).
        return array_slice([...$top, ...$body, ...$bottom], 0, max(0, $height));
    }

    /** Rows left for the body once fixed chrome is subtracted (never negative). */
    public static function bodyBudget(int $top, int $bottom, int $height): int
    {
        return max(0, $height - $top - $bottom);
    }
}
