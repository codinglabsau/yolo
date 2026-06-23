<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Commands\DestroyEnvironmentCommand;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared network-reclaim behaviour for the destroy commands. Destroying an
 * environment tears its network shell (Tier B) down automatically — the one thing
 * that holds it back is a database: a surviving RDS instance lives in the VPC's
 * private subnets and pins the whole network, and YOLO never deletes a database it
 * doesn't own. So a live DB leaves the network standing and is named in the refusal
 * summary; otherwise the shell goes with the rest of the environment.
 */
trait ReclaimsNetwork
{
    /** @var array<int, string>|null memoised live DB identifiers in the env VPC */
    private ?array $liveDatabasesInVpc = null;

    /**
     * The network shell is reclaimed whenever no database is attached to the VPC.
     */
    protected function reclaimsNetwork(): bool
    {
        return $this->liveDatabases() === [];
    }

    /**
     * The Tier-B teardown steps, appended after Tier A — empty when a database
     * blocks the reclaim.
     *
     * @return array<int, class-string>
     */
    protected function networkSteps(): array
    {
        return $this->reclaimsNetwork() ? DestroyEnvironmentCommand::tierBSteps() : [];
    }

    /**
     * @return array<int, string>
     */
    protected function liveDatabases(): array
    {
        if ($this->liveDatabasesInVpc !== null) {
            return $this->liveDatabasesInVpc;
        }

        try {
            return $this->liveDatabasesInVpc = Rds::instancesInVpc((new Vpc())->arn());
        } catch (ResourceDoesNotExistException) {
            // No VPC (already reclaimed, or never created) → nothing attached.
            return $this->liveDatabasesInVpc = [];
        }
    }

    /**
     * The refusal summary line: present only when a database keeps the network
     * shell standing, naming the blocking instance(s).
     *
     * @return array<int, string>
     */
    protected function networkWarnings(): array
    {
        if (($databases = $this->liveDatabases()) !== []) {
            return [sprintf(
                'Refusing to reclaim the network shell — the database(s) %s are still attached to this environment\'s VPC. YOLO never deletes a database it doesn\'t own; snapshot and drop them out-of-band, then re-run to reclaim the network.',
                implode(', ', $databases),
            )];
        }

        return [];
    }
}
