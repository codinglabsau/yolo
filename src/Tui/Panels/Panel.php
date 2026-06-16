<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

/**
 * A TUI tab. The shell owns the loop, the global health bar and the tab bar; a
 * Panel owns one tab's body and its key handling. render() returns ANSI-tagged
 * lines (pure — it never touches the terminal), so panels are testable frame by
 * frame; the shell paints them. gather() refreshes live AWS state each tick.
 * Panels colour themselves directly off the Theme enum.
 */
interface Panel
{
    /** The tab label shown in the tab bar. */
    public function title(): string;

    /** The hotkey that jumps straight to this tab (e.g. 's'). */
    public function hotkey(): string;

    /** Refresh live state from AWS. Called on each poll tick before render(). */
    public function gather(): void;

    /**
     * The body lines for this tab — themed and wrapped to $width, clipped/scrolled
     * to at most $height rows (the budget the shell has left after its chrome). No
     * terminal writes. Height-agnostic panels may over-produce; the shell clips.
     *
     * @return array<int, string>
     */
    public function render(int $width, int $height): array;

    /**
     * The footer key hints for this tab (e.g. ['↑↓ select', '⏎ actions']).
     *
     * @return array<int, string>
     */
    public function hints(): array;

    /**
     * Handle a keypress while this tab is active — navigation only (scrolling, or
     * cycling a group). A panel mutates its own view state; the dashboard is
     * read-only, so a key never dispatches an action.
     */
    public function onKey(string $key): void;
}
