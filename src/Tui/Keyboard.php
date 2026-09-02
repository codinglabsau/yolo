<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui;

/**
 * Raw, no-echo mode so single keypresses arrive without Enter. Always restore()
 * on the way out — raw mode outlives the process otherwise.
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

    public static function decode(string $bytes): string
    {
        return match ($bytes) {
            "\e[A", "\eOA" => 'up',
            "\e[B", "\eOB" => 'down',
            "\e[C", "\eOC" => 'right',
            "\e[D", "\eOD" => 'left',
            "\e[5~" => 'pageup',
            "\e[6~" => 'pagedown',
            "\e[H", "\eOH", "\e[1~" => 'home',
            "\e[F", "\eOF", "\e[4~" => 'end',
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
