<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncEcrRepositoryStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::ecrRepository();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            Aws::ecr()->createRepository([
                'repositoryName' => AwsResources::ecrRepositoryName(),
                'imageScanningConfiguration' => ['scanOnPush' => true],
                'imageTagMutability' => 'MUTABLE',
                'tags' => Aws::tags([], wrap: 'tags')['tags'],
            ]);

            return StepResult::CREATED;
        }
    }
}
