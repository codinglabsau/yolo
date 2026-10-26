<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Carbon\Carbon;
use Codinglabs\Yolo\Aws\ElastiCache;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;

/**
 * The shared cache's replication group as AWS reports it right now. `cache:update`
 * reads this fresh before every decision, so a re-run resumes from the cluster's
 * real state instead of anything a crashed run remembered.
 */
final readonly class CacheTopology
{
    /** `stopped` stays in so a halted update is surfaced, never silently skipped. */
    public const array PENDING_UPDATE_STATUSES = [
        'not-applied', 'waiting-to-start', 'in-progress', 'stopping', 'stopped', 'scheduling', 'scheduled',
    ];

    /** Comfortably longer than a replica launch, the slowest wait. */
    public const int WAIT_TIMEOUT_SECONDS = 45 * 60;

    /**
     * @param  array<string, mixed>  $group
     */
    public function __construct(public array $group) {}

    public static function read(): self
    {
        return new self(ElastiCache::replicationGroup(self::groupId()));
    }

    public static function groupId(): string
    {
        return (new CacheCluster())->name();
    }

    /**
     * The node the group is created with. The alarms, dashboard and TUI address it
     * by this id, so it has to end every run as the primary and the only member.
     */
    public static function originalNode(): string
    {
        return self::groupId() . '-001';
    }

    public function status(): string
    {
        return (string) ($this->group['Status'] ?? 'unknown');
    }

    /** @return array<int, string> */
    public function members(): array
    {
        return array_values($this->group['MemberClusters'] ?? []);
    }

    public function primary(): ?string
    {
        foreach ($this->group['NodeGroups'][0]['NodeGroupMembers'] ?? [] as $member) {
            if (($member['CurrentRole'] ?? null) === 'primary') {
                return $member['CacheClusterId'];
            }
        }

        return null;
    }

    /** Any member besides the original node is the temporary replica this flow adds. */
    public function temporaryReplica(): ?string
    {
        foreach ($this->members() as $member) {
            if ($member !== self::originalNode()) {
                return $member;
            }
        }

        return null;
    }

    /** `enabling` counts as on: AWS is already committed to it. */
    public function failoverEnabled(): bool
    {
        return in_array($this->group['AutomaticFailover'] ?? 'disabled', ['enabled', 'enabling'], true);
    }

    public function multiAzEnabled(): bool
    {
        return ($this->group['MultiAZ'] ?? 'disabled') === 'enabled';
    }

    /** The topology the group is created with, and the one every run ends on. */
    public function isBaseline(): bool
    {
        return $this->members() === [self::originalNode()]
            && ! $this->failoverEnabled()
            && ! $this->multiAzEnabled();
    }

    /**
     * Why the flow must not start, or null. Two members is an earlier run's
     * temporary replica, so it is resumed in whatever state it was left.
     */
    public function refusal(): ?string
    {
        $members = $this->members();

        if (! in_array(self::originalNode(), $members, true)) {
            return sprintf('%s has no %s member — cache:update only handles the group it creates.', self::groupId(), self::originalNode());
        }

        if (count($members) > 2) {
            return sprintf('%s has %d members — cache:update only handles a single node plus its own temporary replica.', self::groupId(), count($members));
        }

        if (count($members) === 2) {
            return null;
        }

        if ($this->status() !== 'available') {
            return sprintf('%s is %s — wait until it is available, then re-run.', self::groupId(), $this->status());
        }

        if ($this->failoverEnabled() || $this->multiAzEnabled()) {
            return sprintf('%s has automatic failover or Multi-AZ on with a single node — turn them off before running cache:update.', self::groupId());
        }

        return null;
    }

    /**
     * Pending updates, oldest release first: the order AWS released them is the
     * order they were built to apply in.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pendingUpdates(): array
    {
        return collect(ElastiCache::updateActions(self::groupId(), self::PENDING_UPDATE_STATUSES))
            ->sortBy(fn (array $action): int => self::date($action['ServiceUpdateReleaseDate'] ?? null)?->getTimestamp() ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    public static function waitUntilAvailable(): void
    {
        self::waitUntil(fn (self $topology): bool => true, 'become available');
    }

    /**
     * Polls until the group is `available` AND in the state the caller asked for:
     * `available` alone can be read in the instant before AWS starts a change.
     *
     * @param  callable(self): bool  $reached
     */
    public static function waitUntil(callable $reached, string $what, int $interval = 15): self
    {
        $attempts = (int) ceil(self::WAIT_TIMEOUT_SECONDS / $interval);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            WaitReporter::poll();

            $topology = new self(Aws::elastiCache()->describeReplicationGroups(array_filter([
                'ReplicationGroupId' => self::groupId(),
                // Rides the request, as the SDK waiters do, so mocks answer instantly.
                '@http' => $attempt > 0 ? ['delay' => $interval * 1000] : null,
            ]))['ReplicationGroups'][0] ?? []);

            if ($topology->status() === 'available' && $reached($topology)) {
                return $topology;
            }
        }

        throw new IntegrityCheckException(sprintf('%s did not %s within %d minutes — re-run cache:update to resume from its current state.', self::groupId(), $what, self::WAIT_TIMEOUT_SECONDS / 60));
    }

    /** The SDK hydrates timestamps as DateTimeResult; mocks hand back strings. */
    public static function date(mixed $value): ?Carbon
    {
        return match (true) {
            $value instanceof \DateTimeInterface => Carbon::instance($value),
            is_string($value) && $value !== '' => Carbon::parse($value),
            default => null,
        };
    }
}
