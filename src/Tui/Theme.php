<?php

namespace Codinglabs\Yolo\Tui;

/**
 * Truecolor hex; Symfony's OutputFormatter degrades to the nearest 256/16 colour
 * where truecolor is unsupported, so callers only ever name a role.
 */
enum Theme: string
{
    case Primary = '#2DD4D4';    // brand cyan — titles, sparkline start, tab labels
    case Healthy = '#A3E635';    // lime — OK states, healthy counts, sparkline end
    case Selection = '#FF2E97';  // neon magenta — the focused row / control
    case Accent = '#A24BFF';     // purple — rules, the active-panel border
    case Active = '#FFC83D';     // gold — active tab + underline, "deploying"
    case Warning = '#FF8A1F';    // orange — drift, latency creep
    case Danger = '#FF3B5C';     // neon red — dead tier, FAILED, destroy
    case Canvas = '#160E2E';     // deep indigo — selected-row text on neon fills
    case Muted = '#6C7A99';      // slate — inactive tabs, hints, borders
    case Text = '#E6ECF5';       // off-white — body text

    public function fg(string $text): string
    {
        return sprintf('<fg=%s>%s</>', $this->value, $text);
    }

    public function bold(string $text): string
    {
        return sprintf('<fg=%s;options=bold>%s</>', $this->value, $text);
    }

    public function bg(string $text, self $fg = self::Canvas): string
    {
        return sprintf('<fg=%s;bg=%s>%s</>', $fg->value, $this->value, $text);
    }
}
