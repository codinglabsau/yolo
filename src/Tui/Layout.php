<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui;

/**
 * Always exactly $height rows so Screen's in-place repaint never scrolls the
 * alternate buffer; the body is padded so the footer pins to the last row.
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

        $body = array_pad(array_slice(array_values($body), 0, $budget), $budget, '');

        // The chrome alone can overflow a very short terminal — clip to $height (top chrome wins).
        return array_slice([...$top, ...$body, ...$bottom], 0, max(0, $height));
    }

    public static function bodyBudget(int $top, int $bottom, int $height): int
    {
        return max(0, $height - $top - $bottom);
    }
}
