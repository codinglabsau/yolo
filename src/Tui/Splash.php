<?php

namespace Codinglabs\Yolo\Tui;

/**
 * The launch splash. The rocket sits on the pad while the first fetch runs, then
 * lifts off — climbing a constant-height canvas with a growing flame/exhaust
 * trail — while the YOLO wordmark wipes in left-to-right (cyan→lime, the logo's
 * gradient) and the tagline lands as it settles. Any keypress skips it; the
 * caller gates it off for non-TTY / NO_COLOR shells.
 */
class Splash
{
    /** How many rows the rocket climbs across the animation. */
    public const LIFT = 5;

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
     * The thrust + exhaust glyphs below the rocket, brightest first, fading down
     * the trail. Index 0 is the always-lit base thrust; deeper rows appear as the
     * rocket climbs.
     *
     * @var array<int, array{0: string, 1: Theme}>
     */
    private const array TRAIL = [
        ['◢◤◢◤◢', Theme::Active],
        ['▒▓▓▒', Theme::Active],
        ['░▒▒░', Theme::Warning],
        [' ░░ ', Theme::Warning],
        [' ·· ', Theme::Muted],
        ['  ·  ', Theme::Muted],
    ];

    /**
     * One animation frame at $progress (0 = on the pad, 1 = settled). The canvas
     * is a constant height so the in-place repaint never jumps: as the rocket
     * rises, the blank rows above it become exhaust-trail rows below it.
     *
     * @return array<int, string>
     */
    public static function frame(float $progress, int $width = 80, string $status = ''): array
    {
        $progress = max(0.0, min(1.0, $progress));
        $above = (int) round((1.0 - $progress) * self::LIFT);
        $trail = self::LIFT - $above;

        $rows = array_fill(0, $above, '');

        foreach (self::ROCKET as $line) {
            $rows[] = self::centre(Theme::Text->fg($line), mb_strlen($line), $width);
        }

        foreach (array_slice(self::TRAIL, 0, $trail + 1) as [$glyph, $colour]) {
            $rows[] = self::centre($colour->fg($glyph), mb_strlen($glyph), $width);
        }

        $rows[] = '';

        foreach (self::wordmarkReveal($progress) as [$tagged, $rawLength]) {
            $rows[] = self::centre($tagged, $rawLength, $width);
        }

        $rows[] = '';
        $rows[] = $progress >= 1.0
            ? self::centre(Theme::Accent->fg(self::TAGLINE), mb_strlen(self::TAGLINE), $width)
            : '';

        // A boot line for the pad-wait (the rocket holds here while the first
        // fetch runs). Always a row — empty during the launch — so the height
        // stays constant and the repaint never jumps.
        $rows[] = $status === ''
            ? ''
            : self::centre(Theme::Muted->fg('▸ ' . $status), mb_strlen('▸ ' . $status), $width);

        return $rows;
    }

    /**
     * The wordmark wiped in left-to-right to $progress — the revealed run split
     * cyan (left half) → lime (right half), echoing the logo. Each entry is
     * [taggedVisible, fullLineLength] so centring stays fixed as it fills.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function wordmarkReveal(float $progress): array
    {
        $progress = max(0.0, min(1.0, $progress));
        $longest = max(array_map(mb_strlen(...), self::WORD));
        $reveal = (int) ceil($progress * $longest);

        return array_map(static function (string $line) use ($reveal): array {
            $length = mb_strlen($line);
            $shown = min($reveal, $length);
            $mid = (int) ceil($length / 2);

            $cyan = mb_substr($line, 0, min($shown, $mid));
            $lime = $shown > $mid ? mb_substr($line, $mid, $shown - $mid) : '';

            $tagged = Theme::Primary->fg($cyan) . ($lime === '' ? '' : Theme::Healthy->fg($lime));

            return [$tagged, $length];
        }, self::WORD);
    }

    private static function centre(string $tagged, int $rawLength, int $width): string
    {
        return str_repeat(' ', max(0, intdiv($width - $rawLength, 2))) . $tagged;
    }

    /**
     * Hold the rocket on the pad while $work (the first fetch) runs, then play the
     * launch — progress 0→1 over ~1.2s — and settle briefly. Any keypress skips.
     *
     * @codeCoverageIgnore timing + terminal I/O — exercised by hand, not in CI.
     */
    public function play(Screen $screen, Keyboard $keyboard, callable $work, string $status = ''): void
    {
        $width = $screen->width();

        $screen->paint(self::frame(0.0, $width, $status));
        $work();

        $steps = 26;

        for ($step = 0; $step <= $steps; $step++) {
            if ($keyboard->read() !== null) {
                return;
            }

            $screen->paint(self::frame($step / $steps, $width));
            usleep(42_000);
        }

        $settle = microtime(true) + 0.5;

        while (microtime(true) < $settle) {
            if ($keyboard->read() !== null) {
                return;
            }

            usleep(40_000);
        }
    }
}
