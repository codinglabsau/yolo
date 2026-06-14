<?php

namespace Codinglabs\Yolo\Tui;

use Symfony\Component\Console\Terminal;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Owns the raw terminal: the alternate-screen buffer, cursor visibility, and the
 * low-flicker in-place repaint the status loop pioneered (home, clear each line,
 * wipe below). The shell hands whole frames to paint(); Screen never decides
 * what's on them.
 *
 * @codeCoverageIgnore raw terminal control — exercised by hand, not in CI.
 */
class Screen
{
    protected Terminal $terminal;

    protected bool $active = false;

    public function __construct(protected OutputInterface $output)
    {
        $this->terminal = new Terminal();
    }

    /** Switch to the alternate buffer, hide the cursor, and clear. */
    public function open(): void
    {
        $this->output->write("\e[?1049h\e[?25l\e[2J");
        $this->active = true;
    }

    /** Restore the cursor and the user's original buffer + scrollback. */
    public function close(): void
    {
        if (! $this->active) {
            return;
        }

        $this->output->write("\e[?25h\e[?1049l");
        $this->active = false;
    }

    /**
     * Repaint the frame in place — home the cursor, overwrite each line (clearing
     * it first so a now-shorter line leaves no tail), then wipe any stale rows
     * below.
     *
     * @param  array<int, string>  $lines
     */
    public function paint(array $lines): void
    {
        $this->output->write("\e[H");

        foreach ($lines as $line) {
            $this->output->write("\e[2K" . $line . "\n");
        }

        $this->output->write("\e[J");
    }

    public function width(): int
    {
        return $this->terminal->getWidth();
    }

    public function height(): int
    {
        return $this->terminal->getHeight();
    }
}
