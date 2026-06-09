<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Contracts\ExecutesWafStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;

/**
 * Attach the env web ACL to the env ALB. Bespoke rather than Resource-backed: the
 * association lives on the load balancer, not the ACL, and WAFv2 exposes it as a
 * single getWebACLForResource / associateWebACL pair. Reconciles idempotently —
 * already bound to our ACL is a no-op; bound to nothing (or something else) is
 * (re)pointed at ours. The change is recorded before the dry-run guard so a
 * drifted association is reported on the plan and survives to the apply pass.
 */
class SyncWafAssociationStep implements ExecutesWafStep
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $loadBalancerArn = (new LoadBalancer())->arn();
        $webAclArn = (new WebAcl())->arn();

        $current = Aws::wafV2()->getWebACLForResource([
            'ResourceArn' => $loadBalancerArn,
        ])['WebACL']['ARN'] ?? null;

        if ($current === $webAclArn) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make('web-acl-association', $current, $webAclArn));

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        Aws::wafV2()->associateWebACL([
            'WebACLArn' => $webAclArn,
            'ResourceArn' => $loadBalancerArn,
        ]);

        return StepResult::SYNCED;
    }
}
