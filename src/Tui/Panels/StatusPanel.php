<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

use Closure;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The landing tab — the live status the `yolo status` command shows (per-group
 * vitals, load, and any in-flight rollout), reusing the proven RendersServiceStatus
 * renderer. Read-only; the global bar above it carries the at-a-glance health.
 */
class StatusPanel implements Panel
{
    use RendersServiceStatus;

    /** @var array<int, array<string, mixed>> */
    protected array $statuses = [];

    public function __construct(public OutputInterface $output) {}

    public function title(): string
    {
        return 'Status';
    }

    public function hotkey(): string
    {
        return 's';
    }

    public function gather(): void
    {
        $this->statuses = static::gatherServiceStatuses(withLoad: true);
    }

    public function render(int $width): array
    {
        return $this->statusLines($this->statuses, time(), deployments: true, load: true);
    }

    public function hints(): array
    {
        return ['live'];
    }

    public function onKey(string $key): ?Closure
    {
        return null;
    }
}
