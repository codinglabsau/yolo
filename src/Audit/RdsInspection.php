<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Audit;

use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Manifest;
use Aws\Rds\Exception\RdsException;

/**
 * A read-only health snapshot of the database an app is wired to — the RDS
 * instance or Aurora cluster DECLARED by the manifest `database:` key (see
 * {@see Manifest::rdsTarget()}). It is NOT a YOLO-managed, YOLO-tagged resource,
 * so it never shows up in the tag-based audit inventory; the audit health check
 * looks it up directly by the manifest identifier instead.
 *
 * Two things matter to the health check:
 *  - **deletion protection** — an unprotected production database is an error
 *    (the audit exits non-zero on it), so a single fat-fingered console delete
 *    can't take the app's data with it.
 *  - **topology basics** — engine, version, size and (for Aurora) the writer +
 *    reader members, surfaced as informational context, never a failure.
 *
 * Reads run under the audit's read-only Observer tier, which already grants
 * `rds:Describe*`. When the declared database can't be read — it doesn't exist,
 * or the tier was denied — the snapshot degrades to `readable: false` with a
 * reason, which the command renders as a warning (never an error): we can't
 * assert protection is off, only that we couldn't confirm it's on.
 */
final readonly class RdsInspection
{
    /**
     * @param  array<int, array{identifier: string, role: string, class: string|null, promotionTier: int|null}>  $members
     */
    private function __construct(
        public bool $readable,
        public ?string $reason,
        public string $identifier,
        public bool $cluster,
        public ?bool $deletionProtection,
        public ?string $engine,
        public ?string $engineVersion,
        public ?string $status,
        public ?string $instanceClass,
        public ?int $allocatedStorage,
        public ?bool $multiAz,
        public array $members,
    ) {}

    /**
     * Inspect the database the manifest declares, or null when none is declared
     * (no `database:` key) — there is simply nothing to check.
     */
    public static function inspect(): ?self
    {
        $target = Manifest::rdsTarget();

        if ($target === null) {
            return null;
        }

        return $target['cluster']
            ? self::inspectCluster($target['identifier'])
            : self::inspectInstance($target['identifier']);
    }

    /**
     * True only when we read the database AND deletion protection is explicitly on.
     * An unreadable snapshot is never "protected" — but it's a warning, not the
     * error an explicit `false` is (see the command's finding severities).
     */
    public function deletionProtectionEnabled(): bool
    {
        return $this->readable && $this->deletionProtection === true;
    }

    public function kind(): string
    {
        return $this->cluster ? 'Aurora cluster' : 'instance';
    }

    /**
     * The informational rows for the basics table: label => value. Engine, version
     * and status always; size/Multi-AZ for a plain instance; the member count for a
     * cluster (the per-member writer/reader breakdown renders as its own table).
     *
     * @return array<string, string>
     */
    public function basics(): array
    {
        $rows = array_filter([
            'Engine' => $this->engineLabel(),
            'Status' => $this->status,
        ]);

        if ($this->cluster) {
            $rows['Members'] = sprintf('%d (%d writer, %d reader)', count($this->members), $this->writerCount(), $this->readerCount());

            return $rows;
        }

        return array_filter([
            ...$rows,
            'Class' => $this->instanceClass,
            'Storage' => $this->allocatedStorage === null ? null : sprintf('%d GiB', $this->allocatedStorage),
            'Multi-AZ' => $this->multiAz === null ? null : ($this->multiAz ? 'yes' : 'no'),
        ]);
    }

    protected static function inspectInstance(string $identifier): self
    {
        try {
            $instance = Rds::instance($identifier);
        } catch (RdsException $exception) {
            return self::unreadable($identifier, false, self::reason($exception));
        }

        if ($instance === null) {
            return self::unreadable($identifier, false, 'no matching DB instance');
        }

        return new self(
            readable: true,
            reason: null,
            identifier: $identifier,
            cluster: false,
            deletionProtection: (bool) ($instance['DeletionProtection'] ?? false),
            engine: $instance['Engine'] ?? null,
            engineVersion: $instance['EngineVersion'] ?? null,
            status: $instance['DBInstanceStatus'] ?? null,
            instanceClass: $instance['DBInstanceClass'] ?? null,
            allocatedStorage: isset($instance['AllocatedStorage']) ? (int) $instance['AllocatedStorage'] : null,
            multiAz: isset($instance['MultiAZ']) ? (bool) $instance['MultiAZ'] : null,
            members: [],
        );
    }

    protected static function inspectCluster(string $identifier): self
    {
        try {
            $cluster = Rds::cluster($identifier);
        } catch (RdsException $exception) {
            return self::unreadable($identifier, true, self::reason($exception));
        }

        if ($cluster === null) {
            return self::unreadable($identifier, true, 'no matching DB cluster');
        }

        // Per-member instance class is a best-effort nicety — an access gap on the
        // instance describe just omits the sizes, never fails the cluster read.
        try {
            $classes = Rds::clusterInstanceClasses($identifier);
        } catch (RdsException) {
            $classes = [];
        }

        return new self(
            readable: true,
            reason: null,
            identifier: $identifier,
            cluster: true,
            deletionProtection: (bool) ($cluster['DeletionProtection'] ?? false),
            engine: $cluster['Engine'] ?? null,
            engineVersion: $cluster['EngineVersion'] ?? null,
            status: $cluster['Status'] ?? null,
            instanceClass: null,
            allocatedStorage: null,
            multiAz: null,
            members: self::members($cluster['DBClusterMembers'] ?? [], $classes),
        );
    }

    /**
     * Normalise DBClusterMembers into render-ready rows, writer(s) first then
     * readers, each ordered by identifier — a stable, scannable order.
     *
     * @param  array<int, array<string, mixed>>  $rawMembers
     * @param  array<string, string>  $classes
     * @return array<int, array{identifier: string, role: string, class: string|null, promotionTier: int|null}>
     */
    protected static function members(array $rawMembers, array $classes): array
    {
        $members = array_map(static fn (array $member): array => [
            'identifier' => $member['DBInstanceIdentifier'] ?? '—',
            'role' => ($member['IsClusterWriter'] ?? false) ? 'writer' : 'reader',
            'class' => $classes[$member['DBInstanceIdentifier'] ?? ''] ?? null,
            'promotionTier' => isset($member['PromotionTier']) ? (int) $member['PromotionTier'] : null,
        ], $rawMembers);

        usort($members, static fn (array $a, array $b): int => [$a['role'] === 'reader', $a['identifier']] <=> [$b['role'] === 'reader', $b['identifier']]);

        return $members;
    }

    protected static function unreadable(string $identifier, bool $cluster, string $reason): self
    {
        return new self(
            readable: false,
            reason: $reason,
            identifier: $identifier,
            cluster: $cluster,
            deletionProtection: null,
            engine: null,
            engineVersion: null,
            status: null,
            instanceClass: null,
            allocatedStorage: null,
            multiAz: null,
            members: [],
        );
    }

    protected static function reason(RdsException $exception): string
    {
        return match (true) {
            in_array($exception->getAwsErrorCode(), ['DBInstanceNotFound', 'DBClusterNotFound'], true) => 'no matching database in this account/region',
            $exception->getStatusCode() === 403, $exception->getAwsErrorCode() === 'AccessDenied' => 'access denied reading the database',
            default => $exception->getAwsErrorCode() ?? 'unknown error',
        };
    }

    protected function engineLabel(): ?string
    {
        if ($this->engine === null) {
            return null;
        }

        return $this->engineVersion === null
            ? $this->engine
            : sprintf('%s %s', $this->engine, $this->engineVersion);
    }

    protected function writerCount(): int
    {
        return count(array_filter($this->members, static fn (array $member): bool => $member['role'] === 'writer'));
    }

    protected function readerCount(): int
    {
        return count($this->members) - $this->writerCount();
    }
}
