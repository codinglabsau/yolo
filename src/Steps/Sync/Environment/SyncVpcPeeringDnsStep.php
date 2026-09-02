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
 * Deliberately the LAST peering act: DNS resolution makes workloads resolve the
 * peer's private hostnames and start sending traffic across the bridge — flip
 * it before every route exists and each new connection black-holes until the
 * routes land. Create/accept therefore leaves DNS off.
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
                // Recorded before the dry-run guard; on a greenfield plan pass the
                // connection doesn't exist yet, and by apply the connection and
                // routes steps have brought it up.
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
