<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\WafV2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;

/**
 * Bespoke rather than Resource-backed: the association lives on the load
 * balancer, not the ACL, and WAFv2 exposes it as a single getWebACLForResource
 * / associateWebACL pair.
 */
class SyncWafAssociationStep implements ExecutesWebStep
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $webAcl = new WebAcl();

        // On the plan pass the web ACL may not exist yet, and its ARN can't be
        // resolved offline (the Id is AWS-assigned) — report pending without touching AWS.
        if (! $webAcl->exists()) {
            $this->recordChange(Change::make('web-acl-association', null, $webAcl->name()));

            return StepResult::WOULD_SYNC;
        }

        $loadBalancerArn = (new LoadBalancer())->arn();
        $webAclArn = $webAcl->arn();

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

        // A just-created web ACL isn't immediately associable, and on a greenfield
        // sync (ACL moments old, ALB just past `active`) propagation has been
        // observed to outlast the default budget — failing the last step of a first
        // sync that a plain re-run completes. Steady-state runs short-circuit above.
        WafV2::retryWhileUnavailable(fn () => Aws::wafV2()->associateWebACL([
            'WebACLArn' => $webAclArn,
            'ResourceArn' => $loadBalancerArn,
        ]), maxAttempts: 24, sleepSeconds: 5);

        return StepResult::SYNCED;
    }
}
