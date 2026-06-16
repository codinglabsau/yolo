<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

use Codinglabs\Yolo\Tui\Chart;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Tui\Viewport;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Wide CPU / memory (and, for the web group, request-rate / response-time) braille
 * charts over the last hour — the readable replacement for the inline sparkline on
 * the Overview tab. One chart per metric per group, scrollable when they overflow.
 * Read-only.
 */
class MetricsPanel implements Panel
{
    use RendersServiceStatus;

    /** The trend window, in minutes — 60 reads well without dwarfing the screen; longer → AWS console. */
    public const WINDOW_MINUTES = 60;

    /** Braille rows per chart — tall enough to read a shape, short enough to fit a few per screen. */
    public const CHART_HEIGHT = 5;

    /** @var array<int, array{group: ServerGroup, load: array{cpu: float|null, memory: float|null, requests: float|null, response: float|null, series: array{cpu: array<int, float>, memory: array<int, float>, requests: array<int, float>, response: array<int, float>}}}> */
    protected array $groups = [];

    protected int $bodyHeight = 0;

    public function __construct(public OutputInterface $output, protected Viewport $viewport = new Viewport(followTail: false)) {}

    public function title(): string
    {
        return 'Metrics';
    }

    public function hotkey(): string
    {
        return 'm';
    }

    public function gather(): void
    {
        $this->groups = static::gatherMetricsSeries(self::WINDOW_MINUTES);
    }

    public function render(int $width, int $height): array
    {
        $header = [
            Theme::Primary->bold('  metrics') . Theme::Muted->fg('  last ' . self::WINDOW_MINUTES . ' min'),
            '',
        ];

        $this->bodyHeight = max(0, $height - count($header));

        return [...$header, ...$this->viewport->window(self::charts($this->groups, $width), $this->bodyHeight)];
    }

    /**
     * Every chart block, stacked: CPU then memory for each group, plus request rate
     * and response time for the web group. Pure — pinned in a test with hand-built
     * series, no AWS. A blank line separates blocks.
     *
     * @param  array<int, array{group: ServerGroup, load: array{cpu: float|null, memory: float|null, requests: float|null, response: float|null, series: array{cpu: array<int, float>, memory: array<int, float>, requests: array<int, float>, response: array<int, float>}}}>  $groups
     * @return array<int, string>
     */
    public static function charts(array $groups, int $width): array
    {
        $caption = 'last ' . self::WINDOW_MINUTES . 'm';
        $lines = [];

        foreach ($groups as $entry) {
            $group = $entry['group']->value;
            $series = $entry['load']['series'];

            $lines = [
                ...$lines,
                ...Chart::render($group . ' · CPU', $series['cpu'], $width, self::CHART_HEIGHT, 0, 100, '%', $caption, Theme::Primary),
                '',
                ...Chart::render($group . ' · Memory', $series['memory'], $width, self::CHART_HEIGHT, 0, 100, '%', $caption, Theme::Accent),
                '',
            ];

            if ($entry['group'] === ServerGroup::WEB) {
                $responseMs = array_map(static fn (float $seconds): float => $seconds * 1000, $series['response']);

                $lines = [
                    ...$lines,
                    ...Chart::render($group . ' · Requests/min', $series['requests'], $width, self::CHART_HEIGHT, 0, Chart::ceiling($series['requests']), '', $caption, Theme::Healthy),
                    '',
                    ...Chart::render($group . ' · Response', $responseMs, $width, self::CHART_HEIGHT, 0, Chart::ceiling($responseMs), 'ms', $caption, Theme::Warning),
                    '',
                ];
            }
        }

        return $lines === [] ? [Theme::Muted->fg('  No services to chart.')] : $lines;
    }

    public function hints(): array
    {
        return ['↑↓ scroll'];
    }

    public function onKey(string $key): void
    {
        match ($key) {
            'up' => $this->viewport->scrollUp(),
            'down' => $this->viewport->scrollDown(),
            'pageup' => $this->viewport->pageUp($this->bodyHeight),
            'pagedown' => $this->viewport->pageDown($this->bodyHeight),
            'home' => $this->viewport->toTop(),
            'end' => $this->viewport->toTail(),
            default => null,
        };
    }
}
