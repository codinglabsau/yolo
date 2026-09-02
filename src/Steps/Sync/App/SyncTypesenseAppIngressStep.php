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
 * Additive task-SG ingress on the Typesense security group, so Scout indexing
 * rides the VPC (Cloud Map node addresses) and never meets the ALB, the WAF or
 * its rate budget.
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
            // The env tier owns the cluster — the rule lands on the sync after it exists.
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
