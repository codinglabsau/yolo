<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui;

/**
 * Non-blocking keyboard reader for the TUI loop. Puts the terminal into raw,
 * no-echo mode so single keypresses arrive immediately (no Enter), reads
 * whatever is waiting each poll, and decodes the common escape sequences (the
 * arrow keys) into stable names. Always restore() on the way out — raw mode
 * outlives the process otherwise.
 */
class Keyboard
{
    protected ?string $original = null;

    /** @codeCoverageIgnore raw terminal I/O */
    public function rawMode(): void
    {
        if (! $this->ttyAvailable()) {
            return;
        }

        $this->original = trim((string) shell_exec('stty -g'));
        shell_exec('stty -echo -icanon min 0 time 0');
        stream_set_blocking(STDIN, false);
    }

    /** @codeCoverageIgnore raw terminal I/O */
    public function restore(): void
    {
        if ($this->original !== null) {
            shell_exec('stty ' . $this->original);
            $this->original = null;
        }
    }

    /**
     * Read whatever keypress is waiting, or null if none. Impure — each poll may
     * return a different key (or nothing), so callers can poll it in a loop.
     *
     * @phpstan-impure
     *
     * @codeCoverageIgnore raw terminal I/O
     */
    public function read(): ?string
    {
        $bytes = fread(STDIN, 8);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        return static::decode($bytes);
    }

    /**
     * Map a raw byte sequence to a stable key name — arrows to up/down/left/right,
     * the control keys by name, printable keys through unchanged.
     */
    public static function decode(string $bytes): string
    {
        return match ($bytes) {
            "\e[A", "\eOA" => 'up',
            "\e[B", "\eOB" => 'down',
            "\e[C", "\eOC" => 'right',
            "\e[D", "\eOD" => 'left',
            "\t" => 'tab',
            "\r", "\n" => 'enter',
            "\e" => 'esc',
            "\x03" => 'ctrl-c',
            default => $bytes,
        };
    }

    /** @codeCoverageIgnore raw terminal I/O */
    protected function ttyAvailable(): bool
    {
        return stream_isatty(STDIN) && function_exists('shell_exec');
    }
}
