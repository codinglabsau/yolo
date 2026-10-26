<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\CacheUpdate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\WaitReporter;
use Codinglabs\Yolo\CacheTopology;
use Codinglabs\Yolo\Aws\ElastiCache;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * One update at a time, each run to completion before the next starts. The fresh
 * replica already carries them, so AWS fails over to it and patches the old
 * primary; later updates hit that node as a replica, out of the clients' path.
 */
class ApplyCacheServiceUpdatesStep implements LongRunning
{
    /** A single update has been seen sitting in `waiting-to-start` for 20+ minutes. */
    public const int POLL_SECONDS = 30;

    public const int TIMEOUT_SECONDS = 2 * 3600;

    public const array HALTED_STATUSES = ['stopping', 'stopped'];

    public function __invoke(array $options): StepResult
    {
        $pending = CacheTopology::pendingUpdates();

        if ($pending === []) {
            return StepResult::SKIPPED;
        }

        foreach ($pending as $index => $action) {
            $name = (string) $action['ServiceUpdateName'];
            $label = sprintf('[%d/%d] %s', $index + 1, count($pending), $name);

            static::assertNotHalted($action);

            // Anything past not-applied was started by an earlier run, so it is
            // waited on, not applied twice.
            if ($action['UpdateActionStatus'] === 'not-applied') {
                CacheTopology::waitUntilAvailable();

                static::apply($name);
            }

            static::waitForCompletion($name, $label);
        }

        return StepResult::SYNCED;
    }

    /** Only the group can be targeted; AWS picks the node order within it. */
    protected static function apply(string $name): void
    {
        $result = Aws::elastiCache()->batchApplyUpdateAction([
            'ReplicationGroupIds' => [CacheTopology::groupId()],
            'ServiceUpdateName' => $name,
        ]);

        if (($unprocessed = $result['UnprocessedUpdateActions'] ?? []) !== []) {
            throw new IntegrityCheckException(sprintf(
                'AWS did not accept %s: %s',
                $name,
                $unprocessed[0]['ErrorMessage'] ?? $unprocessed[0]['ErrorType'] ?? 'no reason given',
            ));
        }
    }

    protected static function waitForCompletion(string $name, string $label): void
    {
        $attempts = (int) ceil(self::TIMEOUT_SECONDS / self::POLL_SECONDS);
        $action = [];

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $action = ElastiCache::updateActions(CacheTopology::groupId(), serviceUpdateName: $name, nodeLevel: true, delaySeconds: $attempt > 0 ? self::POLL_SECONDS : 0)[0]
                ?? throw new IntegrityCheckException(sprintf('%s is no longer listed against %s.', $name, CacheTopology::groupId()));

            if ($action['UpdateActionStatus'] === 'complete') {
                return;
            }

            static::assertNotHalted($action);

            WaitReporter::line(sprintf('%s · %s', $label, static::progress($action)));
            WaitReporter::poll();
        }

        throw new IntegrityCheckException(sprintf('%s was still %s after %d minutes — re-run cache:update to keep waiting on it.', $name, $action['UpdateActionStatus'] ?? 'pending', self::TIMEOUT_SECONDS / 60));
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected static function assertNotHalted(array $action): void
    {
        if (in_array($action['UpdateActionStatus'] ?? null, self::HALTED_STATUSES, true)) {
            throw new IntegrityCheckException(sprintf(
                '%s is %s — investigate in the ElastiCache console before re-running. The temporary replica is left in place, so the cache stays highly available meanwhile.',
                $action['ServiceUpdateName'],
                $action['UpdateActionStatus'],
            ));
        }
    }

    /**
     * Each node's own status, e.g. "-001 waiting-to-start, -002 complete".
     *
     * @param  array<string, mixed>  $action
     */
    public static function progress(array $action): string
    {
        $nodes = collect($action['NodeGroupUpdateStatus'] ?? [])
            ->flatMap(fn (array $group): array => $group['NodeGroupMemberUpdateStatus'] ?? [])
            ->map(fn (array $node): string => sprintf('%s %s', substr((string) $node['CacheClusterId'], strlen(CacheTopology::groupId())), $node['NodeUpdateStatus'] ?? 'unknown'))
            ->implode(', ');

        return $nodes !== '' ? $nodes : (string) $action['UpdateActionStatus'];
    }

    public function patienceMessage(): string
    {
        return 'Applying service updates — each can wait 10–20+ minutes before AWS starts it';
    }
}
