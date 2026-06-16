<?php

namespace Codinglabs\Yolo\Tui\Panels;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Tui\Viewport;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;

/**
 * Tails CloudWatch logs for one service group at a time — the "why" to the Status
 * tab's "what". `g` cycles through the groups the app runs (web/queue/scheduler);
 * ↑↓ / PgUp PgDn scroll the buffer and ⌂ jumps back to the live tail. Read-only.
 */
class LogsPanel implements Panel
{
    /** @var array<int, array<string, mixed>> */
    protected array $events = [];

    protected ServerGroup $group = ServerGroup::WEB;

    protected int $groupIndex = 0;

    /** Rows the log body occupied last render — drives PgUp/PgDn paging. */
    protected int $bodyHeight = 0;

    public function __construct(protected Viewport $viewport = new Viewport()) {}

    public function title(): string
    {
        return 'Logs';
    }

    public function hotkey(): string
    {
        return 'l';
    }

    public function gather(): void
    {
        $groups = Manifest::serverGroups();
        $this->group = $groups === [] ? ServerGroup::WEB : $groups[$this->groupIndex % count($groups)];
        $this->events = CloudWatchLogs::recent((new TaskLogGroup())->name(), $this->group->value, 60);
    }

    public function render(int $width, int $height): array
    {
        $header = [
            Theme::Primary->bold('  logs') . Theme::Muted->fg(sprintf('  %s · %s', $this->group->value, (new TaskLogGroup())->name())),
            '',
        ];

        $this->bodyHeight = max(0, $height - count($header));

        return [...$header, ...$this->viewport->window(self::eventLines($this->events, $width), $this->bodyHeight)];
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
        return ['g cycle group', '↑↓ scroll', '⌂ tail'];
    }

    public function onKey(string $key): void
    {
        match ($key) {
            'g' => $this->cycleGroup(),
            'up' => $this->viewport->scrollUp(),
            'down' => $this->viewport->scrollDown(),
            'pageup' => $this->viewport->pageUp($this->bodyHeight),
            'pagedown' => $this->viewport->pageDown($this->bodyHeight),
            'home' => $this->viewport->toTop(),
            'end' => $this->viewport->toTail(),
            default => null,
        };
    }

    /** Move to the next service group and snap back to the live tail. */
    protected function cycleGroup(): void
    {
        $this->groupIndex++;
        $this->viewport->toTail();
    }
}
