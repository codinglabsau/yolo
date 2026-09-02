<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui;

/** Cells are padded BEFORE the colour tag is applied, so ANSI escapes never count toward the visible width. */
class Columns
{
    /**
     * @param  array<int, array{0: string, 1: int, 2?: Theme|null}>  $cells  [text, width, colour?]
     */
    public static function row(array $cells): string
    {
        $rendered = array_map(static function (array $cell): string {
            $padded = mb_str_pad($cell[0], $cell[1]);
            $colour = $cell[2] ?? null;

            return $colour instanceof Theme ? $colour->fg($padded) : $padded;
        }, $cells);

        return '  ' . implode(' ', $rendered);
    }
}
