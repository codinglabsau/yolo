<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\RevokesTaskIngress;
use Codinglabs\Yolo\Resources\Ec2\CacheSecurityGroup;

/**
 * Revokes this app's "6379 from the task SG" ingress rule from the shared Valkey
 * cache security group — leaving the group (and the cache) for the environment's
 * other apps. Like the RDS revoke, this must run before the task SG is deleted.
 * Only wired into the plan when the app declares a cache store.
 */
class RevokeCacheIngressStep implements ExecutesWebStep
{
    use RevokesTaskIngress;

    public function __invoke(array $options): StepResult
    {
        $securityGroup = new CacheSecurityGroup();

        if (! $securityGroup->exists()) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        if (! $this->revokeTaskIngressRule($securityGroup->arn(), 6379, $dryRun)) {
            return StepResult::SKIPPED;
        }

        return $dryRun ? StepResult::WOULD_DELETE : StepResult::DELETED;
    }
}
