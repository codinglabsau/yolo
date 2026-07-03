<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\VpcPeeringConnection;

/**
 * Tears down every live YOLO-owned peering connection in the environment —
 * whatever the env manifest declared, discovered by tag so a connection whose
 * declaration was already removed still comes down with the environment. Runs
 * before the VPC teardown (a peered VPC can't be deleted).
 */
class TeardownVpcPeeringConnectionsStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $results = [];

        foreach (Ec2::livePeeringConnections(Helpers::environment()) as $connection) {
            if (($peerVpcId = $connection['AccepterVpcInfo']['VpcId'] ?? null) !== null) {
                $results[] = $this->teardownResource(new VpcPeeringConnection($peerVpcId), $options);
            }
        }

        foreach ([StepResult::DELETED, StepResult::WOULD_DELETE] as $priority) {
            if (in_array($priority, $results, true)) {
                return $priority;
            }
        }

        return StepResult::SKIPPED;
    }
}
