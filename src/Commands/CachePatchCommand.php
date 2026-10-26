<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Carbon\Carbon;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\CacheTopology;
use Codinglabs\Yolo\Contracts\AdminCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * On a single node, every ElastiCache service update restarts the cache empty,
 * logging every user out. Patching through a temporary replica makes it a non-event:
 * the replica launches already patched, AWS fails over to it while it patches the
 * original, and the original is promoted back before the replica is removed.
 *
 * Every step reads the group's live state before acting, so a re-run after a crash
 * resumes wherever the cluster was left. A full run takes 30–60 minutes.
 *
 * Admin tier: it reshapes env-shared infrastructure that every app depends on.
 */
class CachePatchCommand extends SteppedCommand implements AdminCommand
{
    /** @var array<int, class-string> */
    protected array $steps = [
        Steps\CachePatch\AddCacheReplicaStep::class,
        Steps\CachePatch\EnableCacheFailoverStep::class,
        Steps\CachePatch\ApplyCacheServiceUpdatesStep::class,
        Steps\CachePatch\FailBackCachePrimaryStep::class,
        Steps\CachePatch\DisableCacheFailoverStep::class,
        Steps\CachePatch\RemoveCacheReplicaStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('cache:patch')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->addOption('no-progress', null, InputOption::VALUE_NONE, 'Hide the progress output')
            ->setDescription('Apply pending service updates to the shared cache with zero downtime, through a temporary replica');
    }

    #[\Override]
    public function handle(): int
    {
        if (! (new CacheCluster())->exists()) {
            error(sprintf('%s has no shared cache — nothing to update.', $this->argument('environment')));

            return self::FAILURE;
        }

        $topology = CacheTopology::read();
        $pending = CacheTopology::pendingUpdates();

        intro(sprintf('yolo cache:patch · %s · %s', $this->argument('environment'), CacheTopology::groupId()));

        if ($pending === [] && $topology->isBaseline()) {
            info('No pending service updates — the cache is up to date.');

            return self::SUCCESS;
        }

        if ($pending !== []) {
            table(['Update', 'Type', 'Severity', 'Released', 'Apply by', 'Status'], static::pendingRows($pending));
        }

        if (($refusal = $topology->refusal()) !== null) {
            error($refusal);

            return self::FAILURE;
        }

        if (! $topology->isBaseline()) {
            note(sprintf(
                'Resuming an earlier run: %s has %d member(s), primary %s, failover %s.',
                CacheTopology::groupId(),
                count($topology->members()),
                $topology->primary() ?? 'unknown',
                $topology->failoverEnabled() ? 'on' : 'off',
            ));
        }

        note($pending === []
            ? 'Nothing left to apply — this run only tears the temporary replica down.'
            : 'Adds a temporary replica in another zone, turns on failover, applies each update oldest first, promotes the original node back and removes the replica. Clients stay connected throughout; expect 30–60 minutes.');

        if (! $this->option('force') && ! confirm(sprintf('Patch %s now?', CacheTopology::groupId()), default: false)) {
            note('Aborted — nothing was changed.');

            return self::SUCCESS;
        }

        try {
            parent::handle();
        } catch (IntegrityCheckException $e) {
            error($e->getMessage());
            warning('Re-running `yolo cache:patch` resumes from the cluster\'s current state.');

            return self::FAILURE;
        }

        return $this->verify();
    }

    protected function verify(): int
    {
        $topology = CacheTopology::read();
        $original = CacheTopology::originalNode();

        $checks = [
            sprintf('only member is %s', $original) => $topology->members() === [$original],
            sprintf('%s is primary', $original) => $topology->primary() === $original,
            'failover and Multi-AZ off' => ! $topology->failoverEnabled() && ! $topology->multiAzEnabled(),
            'no pending service updates' => CacheTopology::pendingUpdates() === [],
        ];

        table(['Check', 'Result'], collect($checks)
            ->map(fn (bool $passed, string $check): array => [$check, $passed ? '<fg=green>pass</>' : '<fg=red>FAIL</>'])
            ->values()
            ->all());

        if (in_array(false, $checks, true)) {
            error('The cache is not back to its baseline — re-run `yolo cache:patch` to resume.');

            return self::FAILURE;
        }

        info(sprintf('%s is patched and back to a single node.', CacheTopology::groupId()));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pending
     * @return array<int, array<int, string>>
     */
    public static function pendingRows(array $pending): array
    {
        return array_map(function (array $action): array {
            $applyBy = CacheTopology::date($action['ServiceUpdateRecommendedApplyByDate'] ?? null);

            return [
                (string) $action['ServiceUpdateName'],
                str_replace('-update', '', (string) ($action['ServiceUpdateType'] ?? 'unknown')),
                (string) ($action['ServiceUpdateSeverity'] ?? 'unknown'),
                CacheTopology::date($action['ServiceUpdateReleaseDate'] ?? null)?->toDateString() ?? '—',
                match (true) {
                    ! $applyBy instanceof Carbon => '—',
                    $applyBy->isPast() => sprintf('<fg=red>%s (overdue)</>', $applyBy->toDateString()),
                    default => $applyBy->toDateString(),
                },
                (string) $action['UpdateActionStatus'],
            ];
        }, $pending);
    }
}
