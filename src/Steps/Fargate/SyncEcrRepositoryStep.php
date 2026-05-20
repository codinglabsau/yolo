<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
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
            $repository = AwsResources::ecrRepository();

            $this->reconcileTags($repository['repositoryArn'], Arr::get($options, 'dry-run'));

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            Aws::ecr()->createRepository([
                'repositoryName' => AwsResources::ecrRepositoryName(),
                'imageScanningConfiguration' => ['scanOnPush' => true],
                'imageTagMutability' => 'MUTABLE',
                'tags' => Aws::tags(['Name' => AwsResources::ecrRepositoryName()], wrap: 'tags')['tags'],
            ]);

            Aws::ecr()->putLifecyclePolicy([
                'repositoryName' => AwsResources::ecrRepositoryName(),
                'lifecyclePolicyText' => static::lifecyclePolicy(),
            ]);

            return StepResult::CREATED;
        }
    }

    protected function reconcileTags(string $arn, bool $dryRun): void
    {
        $current = Aws::flattenTags(Aws::ecr()->listTagsForResource([
            'resourceArn' => $arn,
        ])['tags']);

        $missing = Aws::tagsRequiringSync(
            Aws::expectedTags(['Name' => AwsResources::ecrRepositoryName()]),
            $current,
        );

        if (empty($missing) || $dryRun) {
            return;
        }

        Aws::ecr()->tagResource([
            'resourceArn' => $arn,
            'tags' => collect($missing)
                ->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])
                ->values()
                ->all(),
        ]);
    }

    public static function lifecyclePolicy(): string
    {
        $keepCount = Helpers::validatePositiveInt(
            Manifest::get('tasks.web.image-retention.keep-count', 30),
            'tasks.web.image-retention.keep-count',
        );
        $untaggedDays = Helpers::validatePositiveInt(
            Manifest::get('tasks.web.image-retention.untagged-days', 7),
            'tasks.web.image-retention.untagged-days',
        );

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
