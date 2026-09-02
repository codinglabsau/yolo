<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\RevokesTaskIngress;
use Codinglabs\Yolo\Resources\Ec2\TypesenseSecurityGroup;
use Codinglabs\Yolo\Steps\Sync\App\SyncTypesenseAppIngressStep;

/**
 * Mirror of {@see SyncTypesenseAppIngressStep}. Must run before the task SG is
 * deleted (see RevokeRdsIngressStep).
 */
class RevokeTypesenseIngressStep implements ExecutesWebStep
{
    use RevokesTaskIngress;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::usesService(Service::TYPESENSE)) {
            return StepResult::SKIPPED;
        }

        $securityGroup = new TypesenseSecurityGroup();

        if (! $securityGroup->exists()) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        if (! $this->revokeTaskIngressRule($securityGroup->arn(), Typesense::API_PORT, $dryRun)) {
            return StepResult::SKIPPED;
        }

        return $dryRun ? StepResult::WOULD_DELETE : StepResult::DELETED;
    }
}
