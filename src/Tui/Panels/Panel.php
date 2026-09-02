<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

/**
 * render() returns ANSI-tagged lines and never touches the terminal, so panels
 * are testable frame by frame; the shell paints them.
 */
interface Panel
{
    public function title(): string;

    public function hotkey(): string;

    /** Called on each poll tick before render(). */
    public function gather(): void;

    /**
     * $height is the budget left after the shell's chrome; a panel that over-produces is clipped.
     *
     * @return array<int, string>
     */
    public function render(int $width, int $height): array;

    /**
     * @return array<int, string>
     */
    public function hints(): array;

    /** Navigation only — the dashboard is read-only, so a key never dispatches an action. */
    public function onKey(string $key): void;
}
