<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Change;
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
 * before the VPC teardown (a peered VPC can't be deleted). Each delete
 * reclaims the whole bridge in reverse bring-up order — DNS resolution off,
 * the yolo-side routes, the return routes sync wrote into the peer's tables,
 * then the connection ({@see VpcPeeringConnection::delete}); the foreign
 * route reclaims are named in the plan.
 */
class TeardownVpcPeeringConnectionsStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $results = [];

        foreach (Ec2::livePeeringConnections(Helpers::environment()) as $liveConnection) {
            if (($peerVpcId = $liveConnection['AccepterVpcInfo']['VpcId'] ?? null) !== null) {
                $connection = new VpcPeeringConnection($peerVpcId);

                foreach ($connection->foreignReturnRoutes() as $foreignReturnRoute) {
                    $this->recordChange(Change::make(
                        sprintf('return route %s (peer %s — not yolo-managed)', $foreignReturnRoute['DestinationCidrBlock'], $foreignReturnRoute['RouteTableId']),
                        'peering connection',
                        null,
                    ));
                }

                $results[] = $this->teardownResource($connection, $options);
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
