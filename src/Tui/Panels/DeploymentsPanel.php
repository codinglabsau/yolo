<?php

namespace Codinglabs\Yolo\Tui\Panels;

use Closure;
use Carbon\Carbon;
use Codinglabs\Yolo\Aws\Ecr;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Tui\Columns;
use Codinglabs\Yolo\Tui\DeployObserver;
use Codinglabs\Yolo\Commands\RollbackCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deploy history + rollback. When a rollout is in flight (whoever triggered it),
 * the tab shows the live progress and locks the rollback action; otherwise it
 * lists the last deployments from ECR (newest first, the running one marked) and
 * ⏎ launches the interactive rollback (reusing RollbackCommand).
 */
class DeploymentsPanel implements Panel
{
    use RendersServiceStatus;

    /** @var array<int, array{version: string, pushedAt: int}> */
    protected array $targets = [];

    /** @var array<int, array<string, mixed>> */
    protected array $statuses = [];

    public function __construct(protected string $environment, protected OutputInterface $output) {}

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

    public function render(int $width): array
    {
        if (DeployObserver::active($this->statuses)) {
            return [
                Theme::Active->bold('  ⟳ deploy in progress'),
                '',
                ...$this->statusLines($this->statuses, time(), deployments: true, load: false),
                '',
                Theme::Muted->fg('  rollback disabled until the rollout settles'),
            ];
        }

        return self::historyLines($this->targets, $this->currentVersion());
    }

    /**
     * The deploy-history rows — version, when it was pushed, and a marker on the
     * version that's running now.
     *
     * @param  array<int, array{version: string, pushedAt: int}>  $targets
     * @return array<int, string>
     */
    public static function historyLines(array $targets, ?string $current): array
    {
        if ($targets === []) {
            return [Theme::Muted->fg('  No previous versions in ECR.')];
        }

        $lines = [Theme::Muted->fg('  recent deployments (from ECR)'), ''];

        foreach (array_slice($targets, 0, 10) as $target) {
            $isCurrent = $target['version'] === $current;

            $lines[] = Columns::row([
                [$isCurrent ? '●' : ' ', 1, Theme::Healthy],
                [$target['version'], 18, Theme::Primary],
                ['pushed ' . Carbon::createFromTimestamp($target['pushedAt'])->diffForHumans(), 30, Theme::Muted],
                [$isCurrent ? 'current' : '', 8, Theme::Healthy],
            ]);
        }

        return $lines;
    }

    public function hints(): array
    {
        return ['⏎ roll back'];
    }

    public function onKey(string $key): ?Closure
    {
        if (($key !== 'enter' && $key !== 'r') || DeployObserver::active($this->statuses)) {
            return null;
        }

        return $this->rollback(...);
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

    /**
     * Launch the interactive rollback — the same picker + guards as `yolo rollback`.
     *
     * @codeCoverageIgnore drives Laravel Prompts; verified by hand
     */
    protected function rollback(): void
    {
        $command = new RollbackCommand();
        $command->input = new ArrayInput(['environment' => $this->environment], $command->getDefinition());
        $command->output = $this->output;
        $command->handle();
    }
}
