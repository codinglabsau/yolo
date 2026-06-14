<?php

namespace Codinglabs\Yolo\Tui;

/**
 * The launch splash — a rocket and flame above the cyan→lime YOLO wordmark, in
 * the Outrun palette. It plays over the first AWS fetch (so it masks latency
 * rather than adding it), holds to a ~1s floor so it registers, and any keypress
 * skips it. The caller gates it off for non-TTY / NO_COLOR shells.
 */
class Splash
{
    /** @var array<int, string> */
    public const ROCKET = [
        '    /\\',
        '   /  \\',
        '  | () |',
        '  |    |',
        ' /|    |\\',
        '/_|____|_\\',
    ];

    /** @var array<int, string> */
    public const FLAME = [
        '   )||(',
        '  ◢◤◢◤◢',
    ];

    /** @var array<int, string> */
    public const WORD = [
        '██╗   ██╗ ██████╗ ██╗      ██████╗',
        '╚██╗ ██╔╝██╔═══██╗██║     ██╔═══██╗',
        ' ╚████╔╝ ██║   ██║██║     ██║   ██║',
        '  ╚██╔╝  ██║   ██║██║     ██║   ██║',
        '   ██║   ╚██████╔╝███████╗╚██████╔╝',
        '   ╚═╝    ╚═════╝ ╚══════╝ ╚═════╝',
    ];

    public const TAGLINE = 'deploy · observe · steer';

    /**
     * The composed splash frame — rocket and flame above the cyan→lime wordmark,
     * each row centred to $width. Pure: returns themed lines, writes nothing.
     *
     * @return array<int, string>
     */
    public static function lines(int $width = 80): array
    {
        $rows = [''];

        foreach (static::ROCKET as $line) {
            $rows[] = self::centre(Theme::Text->fg($line), mb_strlen($line), $width);
        }

        foreach (static::FLAME as $line) {
            $rows[] = self::centre(Theme::Active->fg($line), mb_strlen($line), $width);
        }

        $rows[] = '';

        foreach (static::wordmark() as [$tagged, $rawLength]) {
            $rows[] = self::centre($tagged, $rawLength, $width);
        }

        $rows[] = '';
        $rows[] = self::centre(Theme::Accent->fg(static::TAGLINE), mb_strlen((string) static::TAGLINE), $width);
        $rows[] = '';

        return $rows;
    }

    /**
     * Each wordmark line split at its midpoint — left half cyan, right half lime —
     * to echo the logo's gradient cheaply. Returns [taggedLine, rawLength] pairs.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function wordmark(): array
    {
        return array_map(static function (string $line): array {
            $length = mb_strlen($line);
            $mid = (int) ceil($length / 2);

            $tagged = Theme::Primary->fg(mb_substr($line, 0, $mid))
                . Theme::Healthy->fg(mb_substr($line, $mid));

            return [$tagged, $length];
        }, static::WORD);
    }

    private static function centre(string $tagged, int $rawLength, int $width): string
    {
        return str_repeat(' ', max(0, intdiv($width - $rawLength, 2))) . $tagged;
    }

    /**
     * Paint the splash, run $work (the first fetch) underneath it, then hold to a
     * ~1s floor so it's seen — bailing early on any keypress. Never adds time
     * beyond the floor: a slow fetch is simply masked by the frame.
     *
     * @codeCoverageIgnore timing + terminal I/O — exercised by hand, not in CI.
     */
    public function play(Screen $screen, Keyboard $keyboard, callable $work): void
    {
        $screen->paint(static::lines($screen->width()));

        $started = microtime(true);
        $work();

        while (microtime(true) - $started < 1.0) {
            if ($keyboard->read() !== null) {
                break;
            }

            usleep(50_000);
        }
    }
}
