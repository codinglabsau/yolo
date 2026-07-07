<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ec2\VpcPeeringConnection;

/**
 * Enables DNS resolution over each declared peering — deliberately the LAST
 * peering act, ordered after the routes step. DNS resolution is the switch
 * that makes workloads resolve the peer's private hostnames (an RDS endpoint)
 * to private IPs and start sending traffic across the bridge; flip it before
 * every route exists and each new connection black-holes until the routes
 * land. The connection create/accept therefore leaves DNS off, and this step
 * turns it on only once both directions can route.
 */
class SyncVpcPeeringDnsStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $declared = EnvManifest::peering();

        if ($declared === []) {
            return StepResult::SKIPPED;
        }

        $pendingPeerVpcIds = [];

        foreach ($declared as $peerVpcId) {
            if (! (new VpcPeeringConnection($peerVpcId))->dnsResolutionEnabled()) {
                // Recorded before the dry-run guard so the plan and apply
                // passes agree. On a greenfield plan pass the connection
                // doesn't exist yet — the change is pending, and by the time
                // this step's apply runs the connection and routes steps above
                // have brought everything up.
                $this->recordChange(Change::make("DNS resolution over peering ({$peerVpcId})", false, true));
                $pendingPeerVpcIds[] = $peerVpcId;
            }
        }

        if ($pendingPeerVpcIds === []) {
            return StepResult::SYNCED;
        }

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        foreach ($pendingPeerVpcIds as $peerVpcId) {
            (new VpcPeeringConnection($peerVpcId))->enableDnsResolution();
        }

        return StepResult::SYNCED;
    }
}
