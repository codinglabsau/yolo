<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * WAFv2 refuses to delete a web ACL while it's associated, and the association
 * is keyed on the ALB ARN, not the ACL — so this runs before either is torn down.
 */
class DisassociateWafStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        try {
            $loadBalancerArn = (new LoadBalancer())->arn();
        } catch (ResourceDoesNotExistException) {
            return StepResult::SKIPPED;
        }

        $current = Aws::wafV2()->getWebACLForResource([
            'ResourceArn' => $loadBalancerArn,
        ])['WebACL']['ARN'] ?? null;

        if ($current === null) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make('web-acl-association', $current, null));

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        Aws::wafV2()->disassociateWebACL(['ResourceArn' => $loadBalancerArn]);

        return StepResult::DELETED;
    }
}
