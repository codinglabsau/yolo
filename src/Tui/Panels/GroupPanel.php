<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Tui\Chart;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Tui\Viewport;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Output\OutputInterface;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;

/**
 * Everything about one service group (web / queue / scheduler) in a single tab:
 * its vitals (task counts, spec, scaling, live load), wide CPU / memory (and, for
 * web, request-rate / response-time) braille charts over the last hour, and a tail
 * of its recent CloudWatch logs. One tab per group the app actually runs, so a
 * combined app shows only Web. Read-only — the vitals/charts/logs blocks are pure
 * and pinned in tests; only the live AWS gather touches the network.
 */
class GroupPanel implements Panel
{
    use RendersServiceStatus;

    /** The chart trend window, in minutes — matches the Metrics window it replaces. */
    public const WINDOW_MINUTES = 60;

    /** Braille rows per chart — tall enough to read a shape, short enough to stack a few. */
    public const CHART_HEIGHT = 5;

    /** Recent log events to tail in the group's logs block. */
    public const LOG_LINES = 40;

    /** @var array<string, mixed> */
    protected array $status = [];

    /** @var array{cpu: array<int, float>, memory: array<int, float>, requests: array<int, float>, response: array<int, float>} */
    protected array $series = ['cpu' => [], 'memory' => [], 'requests' => [], 'response' => []];

    /** @var array<int, array<string, mixed>> */
    protected array $events = [];

    protected int $bodyHeight = 0;

    public function __construct(
        protected ServerGroup $group,
        public OutputInterface $output,
        protected Viewport $viewport = new Viewport(followTail: false),
    ) {}

    public function title(): string
    {
        return ucfirst($this->group->value);
    }

    public function hotkey(): string
    {
        return match ($this->group) {
            ServerGroup::WEB => 'w',
            ServerGroup::QUEUE => 'u',
            ServerGroup::SCHEDULER => 'h',
        };
    }

    public function gather(): void
    {
        $this->status = static::gatherServiceStatus($this->group);
        $this->series = static::gatherLoad(
            $this->group,
            (new EcsCluster())->name(),
            (new EcsService($this->group))->name(),
            self::WINDOW_MINUTES * 60,
        )['series'];
        $this->events = CloudWatchLogs::recent((new TaskLogGroup())->name(), $this->group->value, self::LOG_LINES);
    }

    public function render(int $width, int $height): array
    {
        // Default the group/exists keys so a pre-gather render (or a cold service)
        // still draws the not-deployed vitals rather than tripping on a bare array.
        $status = [...['group' => $this->group, 'exists' => false], ...$this->status];

        $header = [...self::vitals($status), ''];

        $this->bodyHeight = max(0, $height - count($header));

        $body = [
            ...self::charts($this->group, $this->series, $width),
            '',
            Theme::Primary->bold('  logs') . Theme::Muted->fg(sprintf('  %s · last %d', $this->group->value, count($this->events))),
            ...self::eventLines($this->events, $width),
        ];

        return [...$header, ...$this->viewport->window($body, $this->bodyHeight)];
    }

    /**
     * The group's headline: task counts + spec, its scaling stance, and the live
     * load reading — the same canonical strings the Overview table renders, one
     * group at a time. A service that isn't deployed yet collapses to a single
     * muted line. Pure.
     *
     * @param  array<string, mixed>  $status
     * @return array<int, string>
     */
    public static function vitals(array $status): array
    {
        $group = $status['group']->value;

        if (! ($status['exists'] ?? false)) {
            return [Theme::Primary->bold('  ' . $group) . Theme::Muted->fg('  not deployed')];
        }

        return [
            Theme::Primary->bold('  ' . $group)
                . '  ' . static::formatTasks($status['running'], $status['desired'], $status['pending'])
                . Theme::Muted->fg(' · ') . Theme::Text->fg(static::formatSpec($status['cpu'], $status['memory'], $status['launch'])),
            Theme::Muted->fg('  scale ') . Theme::Text->fg(static::formatScaling($status['scaling'], $status['group'])),
            Theme::Muted->fg('  load  ') . static::formatLoad($status['load'], $status['cpuTarget'], $status['group']),
        ];
    }

    /**
     * The group's metric charts: CPU then memory, plus request rate and response
     * time for the web group only. Pure — pinned in a test with hand-built series,
     * no AWS. A blank line separates blocks.
     *
     * @param  array{cpu: array<int, float>, memory: array<int, float>, requests: array<int, float>, response: array<int, float>}  $series
     * @return array<int, string>
     */
    public static function charts(ServerGroup $group, array $series, int $width): array
    {
        $caption = 'last ' . self::WINDOW_MINUTES . 'm';
        $name = $group->value;

        $lines = [
            ...Chart::render($name . ' · CPU', $series['cpu'], $width, self::CHART_HEIGHT, 0, 100, '%', $caption, Theme::Primary),
            '',
            ...Chart::render($name . ' · Memory', $series['memory'], $width, self::CHART_HEIGHT, 0, 100, '%', $caption, Theme::Accent),
        ];

        if ($group !== ServerGroup::WEB) {
            return $lines;
        }

        $responseMs = array_map(static fn (float $seconds): float => $seconds * 1000, $series['response']);

        return [
            ...$lines,
            '',
            ...Chart::render($name . ' · Requests/min', $series['requests'], $width, self::CHART_HEIGHT, 0, Chart::ceiling($series['requests']), '', $caption, Theme::Healthy),
            '',
            ...Chart::render($name . ' · Response', $responseMs, $width, self::CHART_HEIGHT, 0, Chart::ceiling($responseMs), 'ms', $caption, Theme::Warning),
        ];
    }

    /**
     * Format log events as `HH:MM:SS message` lines, oldest at the top. Each
     * message is truncated to the row width *before* it's coloured (so a cut never
     * lands mid-tag), keeping one event to exactly one row for the fixed layout.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, string>
     */
    public static function eventLines(array $events, int $width): array
    {
        if ($events === []) {
            return [Theme::Muted->fg('  No recent log events.')];
        }

        // The fixed prefix: two-space indent + "HH:MM:SS" + a separating space.
        $messageWidth = max(0, $width - 11);

        return array_map(static function (array $event) use ($messageWidth): string {
            $time = date('H:i:s', (int) (((int) ($event['timestamp'] ?? 0)) / 1000));
            $message = Helpers::truncate((string) ($event['message'] ?? ''), $messageWidth);

            return '  ' . Theme::Muted->fg($time) . ' ' . Theme::Text->fg($message);
        }, $events);
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
