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
 * Revokes this app's "8108 from the task SG" ingress rule from the env-shared
 * Typesense node security group — never the group itself, which the cluster and
 * the environment's other consumers keep. The teardown mirror of
 * {@see SyncTypesenseAppIngressStep}, and the
 * same RDS/cache revoke pattern: it must run before the task SG is deleted (AWS
 * refuses to delete a security group another group's rule still references).
 * Self-skips for an app that never consumed Typesense, or once the cluster's SG
 * is already gone.
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
