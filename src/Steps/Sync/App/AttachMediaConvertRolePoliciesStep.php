<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;
use Codinglabs\Yolo\Resources\Iam\MediaConvertRole;

class AttachMediaConvertRolePoliciesStep implements Step
{
    use AttachesRolePolicies;

    protected array $managedPolicies = [
        'arn:aws:iam::aws:policy/AmazonAPIGatewayInvokeFullAccess',
        'arn:aws:iam::aws:policy/AmazonS3FullAccess',
    ];

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('mediaconvert')) {
            return StepResult::SKIPPED;
        }

        return $this->attachRolePolicies(
            (new MediaConvertRole())->name(),
            $this->managedPolicies,
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
