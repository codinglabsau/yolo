<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

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
            : $this->alarmLines($this->alarms);

        $footer = ['', Theme::Muted->fg('  ' . $this->consoleUrl)];

        $this->bodyHeight = max(0, $height - count($header) - count($footer));

        return [...$header, ...$this->viewport->window($body, $this->bodyHeight), ...$footer];
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
