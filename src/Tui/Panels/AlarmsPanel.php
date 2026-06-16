<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\ConsoleUrl;
use Codinglabs\Yolo\Tui\Viewport;
use Codinglabs\Yolo\Concerns\RendersIncidentReads;

/**
 * The app's CloudWatch alarms and their current state — OK / ALARM /
 * INSUFFICIENT_DATA — reusing the exact gather + render the `status:alarms` command
 * uses. Scrollable when there are many; read-only, with a console deep link to the
 * region's alarm list.
 */
class AlarmsPanel implements Panel
{
    use RendersIncidentReads;

    /** @var array<int, array{name: string, state: ?string, reason: ?string}> */
    protected array $alarms = [];

    protected string $consoleUrl = '';

    protected int $bodyHeight = 0;

    public function __construct(protected Viewport $viewport = new Viewport(followTail: false)) {}

    public function title(): string
    {
        return 'Alarms';
    }

    public function hotkey(): string
    {
        return 'a';
    }

    public function gather(): void
    {
        $this->alarms = static::gatherAlarms();
        $this->consoleUrl = ConsoleUrl::cloudWatchAlarms((string) Manifest::get('region'));
    }

    public function render(int $width, int $height): array
    {
        $firing = static::anyAlarmFiring($this->alarms);

        $summary = count($this->alarms) . ' total' . ($firing ? ' · firing' : '');

        $header = [
            Theme::Primary->bold('  alarms') . ($firing ? Theme::Danger : Theme::Muted)->fg('  ' . $summary),
            '',
        ];

        $body = $this->alarms === []
            ? [Theme::Muted->fg('  No alarms for this app.')]
            : array_map(fn (array $alarm): string => self::alarmRow($alarm, $width), $this->alarms);

        $footer = ['', Theme::Muted->fg('  ' . Helpers::truncate($this->consoleUrl, max(0, $width - 2)))];

        $this->bodyHeight = max(0, $height - count($header) - count($footer));

        return [...$header, ...$this->viewport->window($body, $this->bodyHeight), ...$footer];
    }

    /**
     * One alarm as a single fixed-width row — the state badge, the name and (room
     * permitting) the reason, truncated to the panel width. A raw CloudWatch
     * StateReason can run to hundreds of characters; left unbounded it wraps to a
     * second physical row, the frame overruns the screen, and the terminal scrolls
     * the global bar + tab bar out of view. (The `status:alarms` command keeps the
     * full reason — it's one-shot, not a fixed-height frame.)
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
