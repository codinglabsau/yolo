<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\VpcPeeringConnection;

/**
 * A listed VPC gets a connection created and accepted (DNS resolution comes
 * last, from SyncVpcPeeringDnsStep, once the routes exist); a live YOLO
 * connection no longer listed is torn down.
 */
class SyncVpcPeeringStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $declared = EnvManifest::peering();

        $results = [];

        foreach ($declared as $peerVpcId) {
            $results[] = $this->syncResource(new VpcPeeringConnection($peerVpcId), $options);
        }

        foreach ($this->undeclaredPeerVpcIds($declared) as $peerVpcId) {
            $connection = new VpcPeeringConnection($peerVpcId);

            // The return routes written into the peer's tables are foreign writes —
            // their teardown must be as visible in the plan as the sync that wrote them.
            foreach ($connection->foreignReturnRoutes() as $foreignReturnRoute) {
                $this->recordChange(Change::make(
                    sprintf('return route %s (peer %s — not yolo-managed)', $foreignReturnRoute['DestinationCidrBlock'], $foreignReturnRoute['RouteTableId']),
                    'peering connection',
                    null,
                ));
            }

            $results[] = $this->teardownResource($connection, $options);
        }

        return $this->aggregate($results);
    }

    /**
     * The accepter side is always the peer (YOLO requests from the env VPC), so
     * it identifies the connection to tear down.
     *
     * @param  array<int, string>  $declared
     * @return array<int, string>
     */
    protected function undeclaredPeerVpcIds(array $declared): array
    {
        return collect(Ec2::livePeeringConnections(Helpers::environment()))
            ->pluck('AccepterVpcInfo.VpcId')
            ->filter()
            ->reject(fn (string $peerVpcId): bool => in_array($peerVpcId, $declared, true))
            ->values()
            ->all();
    }

    /**
     * Pending work MUST outrank clean SYNCED — a WOULD_CREATE records no Change,
     * so a mixed plan reporting SYNCED would be pruned before apply and the
     * missing connection never created.
     *
     * @param  array<int, StepResult>  $results
     */
    protected function aggregate(array $results): StepResult
    {
        foreach ([
            StepResult::CREATED, StepResult::DELETED,
            StepResult::WOULD_CREATE, StepResult::WOULD_DELETE, StepResult::WOULD_SYNC,
            StepResult::SYNCED,
        ] as $priority) {
            if (in_array($priority, $results, true)) {
                return $priority;
            }
        }

        return StepResult::SKIPPED;
    }
}
