<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\VpcPeeringConnection;

/**
 * Reconciles the environment's peering connections against the env manifest
 * `peering` list — the plan stays declared either way: a listed VPC gets a
 * connection created, accepted and DNS-enabled; a live YOLO connection whose
 * VPC is no longer listed is torn down (the migration is over, the bridge
 * comes down). With nothing declared and nothing live, there is nothing to do.
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
            $results[] = $this->teardownResource(new VpcPeeringConnection($peerVpcId), $options);
        }

        return $this->aggregate($results);
    }

    /**
     * The peer VPCs of live YOLO-owned connections that the manifest no longer
     * declares — the accepter side is always the peer (YOLO requests from the
     * env VPC), so it identifies the connection to tear down.
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
     * One step, many connections: the most eventful per-connection result wins
     * the step's status line, and an empty run is SKIPPED. Pending work MUST
     * outrank clean SYNCED — a WOULD_CREATE records no Change, so a mixed plan
     * reporting SYNCED would be pruned before apply and the missing connection
     * never created.
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
