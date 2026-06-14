<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

use Closure;
use Codinglabs\Yolo\Tui\Theme;

/**
 * A TUI tab. The shell owns the loop, the global health bar and the tab bar; a
 * Panel owns one tab's body and its key handling. render() returns ANSI-tagged
 * lines (pure — it never touches the terminal), so panels are testable frame by
 * frame; the shell paints them. gather() refreshes live AWS state each tick.
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
     * The body lines for this tab — themed and wrapped to $width, no terminal writes.
     *
     * @return array<int, string>
     */
    public function render(int $width, Theme $theme): array;

    /**
     * The footer key hints for this tab (e.g. ['↑↓ select', '⏎ actions']).
     *
     * @return array<int, string>
     */
    public function hints(): array;

    /**
     * Handle a keypress while this tab is active. Return a closure for the shell
     * to run as a modal (it pauses the loop, drops to cooked mode for Laravel
     * Prompts, then resumes), or null when the key is handled in place or ignored.
     */
    public function onKey(string $key): ?Closure;
}
