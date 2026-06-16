<?php

namespace Codinglabs\Yolo\Tui\Panels;

use Carbon\Carbon;
use Codinglabs\Yolo\Aws\Ecr;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Tui\Columns;
use Codinglabs\Yolo\Tui\Viewport;
use Codinglabs\Yolo\Tui\DeployObserver;
use Codinglabs\Yolo\Commands\RollbackCommand;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deploy history. When a rollout is in flight (whoever triggered it) the tab shows
 * the live progress; otherwise it lists the deployments from ECR (newest first, the
 * running one marked) in a scrollable viewport. Read-only — rolling back is `yolo
 * rollback`.
 */
class DeploymentsPanel implements Panel
{
    use RendersServiceStatus;

    /** @var array<int, array{version: string, pushedAt: int}> */
    protected array $targets = [];

    /** @var array<int, array<string, mixed>> */
    protected array $statuses = [];

    /** Rows the history body occupied last render — drives PgUp/PgDn paging. */
    protected int $bodyHeight = 0;

    // History reads newest-first, so the viewport opens at the top, not the tail.
    public function __construct(
        protected OutputInterface $output,
        protected Viewport $viewport = new Viewport(followTail: false),
    ) {}

    public function title(): string
    {
        return 'Deployments';
    }

    public function hotkey(): string
    {
        return 'd';
    }

    public function gather(): void
    {
        $this->statuses = static::gatherServiceStatuses(withLoad: false);
        $this->targets = RollbackCommand::rollbackTargets(Ecr::images((new EcrRepository())->name()));
    }

    public function render(int $width, int $height): array
    {
        if (DeployObserver::active($this->statuses)) {
            return [
                Theme::Active->bold('  ⟳ deploy in progress'),
                '',
                ...$this->statusLines($this->statuses, time(), deployments: true, load: false),
            ];
        }

        $rows = self::historyLines($this->targets, $this->currentVersion());

        if ($this->targets === []) {
            return $rows;
        }

        $header = [Theme::Muted->fg('  recent deployments (from ECR)'), ''];

        $this->bodyHeight = max(0, $height - count($header));

        return [...$header, ...$this->viewport->window($rows, $this->bodyHeight)];
    }

    /**
     * The deploy-history rows — version, when it was pushed, and a marker on the
     * version that's running now. The full set (the viewport scrolls it); an empty
     * ECR returns a single empty-state line instead.
     *
     * @param  array<int, array{version: string, pushedAt: int}>  $targets
     * @return array<int, string>
     */
    public static function historyLines(array $targets, ?string $current): array
    {
        if ($targets === []) {
            return [Theme::Muted->fg('  No previous versions in ECR.')];
        }

        return array_map(static function (array $target) use ($current): string {
            $isCurrent = $target['version'] === $current;

            return Columns::row([
                [$isCurrent ? '●' : ' ', 1, Theme::Healthy],
                [$target['version'], 18, Theme::Primary],
                ['pushed ' . Carbon::createFromTimestamp($target['pushedAt'])->diffForHumans(), 30, Theme::Muted],
                [$isCurrent ? 'current' : '', 8, Theme::Healthy],
            ]);
        }, $targets);
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

    protected function currentVersion(): ?string
    {
        foreach ($this->statuses as $status) {
            if (($status['version'] ?? null) !== null) {
                return $status['version'];
            }
        }

        return null;
    }
}
