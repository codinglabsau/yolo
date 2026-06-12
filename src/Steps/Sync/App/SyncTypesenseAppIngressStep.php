<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Concerns\AuthorisesTaskIngress;
use Codinglabs\Yolo\Resources\Ec2\TypesenseSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * This app's private path to the search cluster: an additive 8108-from-task-SG
 * ingress rule on the Typesense security group — the RDS-3306 pattern, so
 * Scout indexing rides the VPC (Cloud Map node addresses) and never meets the
 * ALB, the WAF or its rate budget. Skips with instructions while the cluster's
 * SG doesn't exist yet (claim published → env sync provisions → this step
 * authorises on the next pass).
 */
class SyncTypesenseAppIngressStep implements Step
{
    use AuthorisesTaskIngress;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::usesService(Service::TYPESENSE)) {
            return StepResult::SKIPPED;
        }

        try {
            $groupId = (new TypesenseSecurityGroup())->arn();
        } catch (ResourceDoesNotExistException) {
            // The cluster isn't provisioned yet — the env tier owns that; the
            // rule lands on the sync after it exists.
            return StepResult::SKIPPED;
        }

        $changed = $this->reconcileTaskIngressRule(
            $groupId,
            Typesense::API_PORT,
            'Scout indexing from the app tasks',
            (bool) Arr::get($options, 'dry-run'),
        );

        if (! $changed) {
            return StepResult::SYNCED;
        }

        return Arr::get($options, 'dry-run') ? StepResult::WOULD_SYNC : StepResult::SYNCED;
    }
}
