<?php

namespace Codinglabs\Yolo\Tui\Panels;

use Closure;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;

/**
 * Tails CloudWatch logs for one service group at a time — the "why" to the Status
 * tab's "what". `g` cycles through the groups the app runs (web/queue/scheduler).
 * Read-only.
 */
class LogsPanel implements Panel
{
    /** @var array<int, array<string, mixed>> */
    protected array $events = [];

    protected ServerGroup $group = ServerGroup::WEB;

    protected int $groupIndex = 0;

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

    public function render(int $width): array
    {
        return [
            Theme::Primary->bold('  logs') . Theme::Muted->fg(sprintf('  %s · %s', $this->group->value, (new TaskLogGroup())->name())),
            '',
            ...self::eventLines($this->events),
        ];
    }

    /**
     * Format log events as `HH:MM:SS message` lines, oldest at the top.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, string>
     */
    public static function eventLines(array $events): array
    {
        if ($events === []) {
            return [Theme::Muted->fg('  No recent log events.')];
        }

        return array_map(static function (array $event): string {
            $time = date('H:i:s', (int) (((int) ($event['timestamp'] ?? 0)) / 1000));

            return '  ' . Theme::Muted->fg($time) . ' ' . Theme::Text->fg(rtrim((string) ($event['message'] ?? '')));
        }, $events);
    }

    public function hints(): array
    {
        return ['g cycle group'];
    }

    public function onKey(string $key): ?Closure
    {
        if ($key === 'g') {
            $this->groupIndex++;
        }

        return null;
    }
}
