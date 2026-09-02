<?php

namespace Codinglabs\Yolo\Tui;

use Symfony\Component\Console\Terminal;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Alternate-screen buffer + low-flicker in-place repaint (home, clear each line, wipe below).
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

    public function open(): void
    {
        $this->output->write("\e[?1049h\e[?25l\e[2J");
        $this->active = true;
    }

    public function close(): void
    {
        if (! $this->active) {
            return;
        }

        $this->output->write("\e[?25h\e[?1049l");
        $this->active = false;
    }

    /**
     * Each line is cleared before it's overwritten so a now-shorter line leaves no tail.
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
