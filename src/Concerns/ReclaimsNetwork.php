<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Commands\DestroyEnvironmentCommand;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * A surviving RDS instance lives in the VPC's private subnets and pins the whole
 * network, and YOLO never deletes a database it doesn't own — so a live DB leaves
 * the network shell (Tier B) standing.
 */
trait ReclaimsNetwork
{
    /** @var array<int, string>|null */
    private ?array $liveDatabasesInVpc = null;

    protected function reclaimsNetwork(): bool
    {
        return $this->liveDatabases() === [];
    }

    /**
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
            return $this->liveDatabasesInVpc = [];
        }
    }

    /**
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
