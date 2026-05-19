<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
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

            Aws::ecr()->putLifecyclePolicy([
                'repositoryName' => AwsResources::ecrRepositoryName(),
                'lifecyclePolicyText' => static::lifecyclePolicy(),
            ]);

            return StepResult::CREATED;
        }
    }

    protected static function lifecyclePolicy(): string
    {
        $keepCount = (int) Manifest::get('tasks.web.image-retention.keep-count', 30);
        $untaggedDays = (int) Manifest::get('tasks.web.image-retention.untagged-days', 7);

        return json_encode([
            'rules' => [
                [
                    'rulePriority' => 1,
                    'description' => "Expire untagged images after $untaggedDays days",
                    'selection' => [
                        'tagStatus' => 'untagged',
                        'countType' => 'sinceImagePushed',
                        'countUnit' => 'days',
                        'countNumber' => $untaggedDays,
                    ],
                    'action' => ['type' => 'expire'],
                ],
                [
                    'rulePriority' => 2,
                    'description' => "Keep last $keepCount tagged images",
                    'selection' => [
                        'tagStatus' => 'any',
                        'countType' => 'imageCountMoreThan',
                        'countNumber' => $keepCount,
                    ],
                    'action' => ['type' => 'expire'],
                ],
            ],
        ]);
    }
}
