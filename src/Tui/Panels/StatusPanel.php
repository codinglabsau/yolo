<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Concerns\RendersIncidentReads;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The landing tab — the one-shot `yolo status --snapshot` picture (per-group vitals,
 * load, and any in-flight rollout), reusing the proven RendersServiceStatus renderer,
 * with an app-wide CloudWatch alarms summary folded on below it. Read-only; the
 * global bar above it carries the at-a-glance per-group health.
 */
class StatusPanel implements Panel
{
    use RendersIncidentReads;
    use RendersServiceStatus;

    /** @var array<int, array<string, mixed>> */
    protected array $statuses = [];

    /** @var array<int, array{label: string, name: string, backlog: int}> */
    protected array $queues = [];

    /** @var array<int, array{name: string, state: ?string, reason: ?string}> */
    protected array $alarms = [];

    public function __construct(public OutputInterface $output) {}

    public function title(): string
    {
        return 'Overview';
    }

    public function hotkey(): string
    {
        return 'o';
    }

    public function gather(): void
    {
        $this->statuses = static::gatherServiceStatuses(withLoad: true);
        $this->queues = static::gatherQueueBacklogs();
        $this->alarms = static::gatherAlarms();
    }

    public function render(int $width, int $height): array
    {
        return [
            ...$this->statusLines($this->statuses, time(), deployments: true, load: true, queues: $this->queues),
            ...self::alarmSummaryLines($this->alarms, $width),
        ];
    }

    /**
     * The app-wide alarm summary that lives on Overview now the standalone Alarms
     * tab is gone: a count + clear/firing headline, and a truncated row per firing
     * alarm (the ones that need attention). The full inventory, OK states and all,
     * stays one command away via `yolo status:alarms`. Empty when the app has no
     * alarms. Pure.
     *
     * @param  array<int, array{name: string, state: ?string, reason: ?string}>  $alarms
     * @return array<int, string>
     */
    public static function alarmSummaryLines(array $alarms, int $width): array
    {
        if ($alarms === []) {
            return [];
        }

        $firing = array_values(array_filter($alarms, static fn (array $alarm): bool => $alarm['state'] === 'ALARM'));

        $summary = count($alarms) . ' total' . ($firing === [] ? ' · all clear' : ' · ' . count($firing) . ' firing');
        $tint = $firing === [] ? Theme::Muted : Theme::Danger;

        return [
            '',
            '  <options=bold>Alarms</> ' . $tint->fg($summary),
            ...array_map(static fn (array $alarm): string => self::alarmRow($alarm, $width), $firing),
        ];
    }

    /**
     * One firing alarm as a single fixed-width row — the state badge, the name and
     * (room permitting) the reason, truncated to the panel width. A raw CloudWatch
     * StateReason can run to hundreds of characters; left unbounded it wraps to a
     * second physical row and the fixed-height frame overruns the screen. Pure.
     *
     * @param  array{name: string, state: ?string, reason: ?string}  $alarm
     */
    public static function alarmRow(array $alarm, int $width): string
    {
        // 2-space indent + the 5-column state badge + a space = 8 fixed columns.
        $budget = max(10, $width - 8);
        $name = Helpers::truncate($alarm['name'], $budget);
        $remaining = $budget - mb_strlen($name);

        $reason = ($alarm['reason'] ?? '') !== '' && $remaining > 4
            ? Theme::Muted->fg(Helpers::truncate(' — ' . $alarm['reason'], $remaining))
            : '';

        return '  ' . static::formatAlarmState($alarm['state']) . ' ' . Theme::Text->fg($name) . $reason;
    }

    public function hints(): array
    {
        return ['live'];
    }

    public function onKey(string $key): void {}
}
