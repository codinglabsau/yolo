<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui;

/**
 * A scrolling window over a line list longer than the rows available to show it.
 * Holds a scroll offset and a follow-tail flag; renders the visible slice plus a
 * one-row "▲ more / ▼ more" indicator when the content is clipped. Pure: the panel
 * owns the Viewport, feeds it the full line list each render, and drives its
 * scroll from key events. Follow-tail keeps logs pinned to the newest line until
 * the operator scrolls up, then re-arms when they scroll back to the bottom.
 */
class Viewport
{
    public function __construct(
        protected int $offset = 0,
        protected bool $followTail = true,
    ) {}

    /**
     * The visible slice of $lines for a body of $height rows. When the content
     * overflows, one row is reserved for the indicator (so the slice is height-1);
     * when it fits, the whole list renders and there's no indicator. The offset is
     * re-clamped every render, so a resize or a shrunk list can never strand it.
     *
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    public function window(array $lines, int $height): array
    {
        $lines = array_values($lines);
        $total = count($lines);

        if ($height <= 0) {
            return [];
        }

        if ($total <= $height) {
            // Everything fits — no indicator, no scrolling.
            $this->offset = 0;

            return $lines;
        }

        $rows = $height - 1; // reserve a row for the indicator
        $maxOffset = $total - $rows;

        // A live tail pins to the new bottom as lines arrive (using the freshly
        // computed maxOffset), before the clamp below catches any stale offset.
        if ($this->followTail) {
            $this->offset = $maxOffset;
        }

        $this->offset = max(0, min($this->offset, $maxOffset));

        // Sitting at the bottom — by scroll or by clamp — (re-)arms tail-following,
        // so new lines keep the window pinned; scrolling up disarmed it.
        $this->followTail = $this->offset >= $maxOffset;

        return [
            ...array_slice($lines, $this->offset, $rows),
            self::indicator($this->offset, $total, $rows),
        ];
    }

    public function scrollUp(int $lines = 1): void
    {
        $this->offset = max(0, $this->offset - $lines);
        $this->followTail = false;
    }

    public function scrollDown(int $lines = 1): void
    {
        // window() re-clamps and re-arms the tail if this lands at the bottom.
        $this->offset += $lines;
    }

    public function pageUp(int $height): void
    {
        $this->scrollUp(max(1, $height - 1));
    }

    public function pageDown(int $height): void
    {
        $this->scrollDown(max(1, $height - 1));
    }

    public function toTop(): void
    {
        $this->offset = 0;
        $this->followTail = false;
    }

    public function toTail(): void
    {
        $this->followTail = true;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function followingTail(): bool
    {
        return $this->followTail;
    }

    /**
     * The clipped-content hint: how many rows are hidden above and below the
     * current window. Empty (no row consumed) when nothing is clipped.
     */
    public static function indicator(int $offset, int $total, int $rows): string
    {
        $above = $offset;
        $below = max(0, $total - $offset - $rows);

        if ($above === 0 && $below === 0) {
            return '';
        }

        $parts = array_filter([
            $above > 0 ? '▲ ' . $above . ' more' : null,
            $below > 0 ? '▼ ' . $below . ' more' : null,
        ]);

        return Theme::Muted->fg('  ' . implode('   ', $parts));
    }
}
