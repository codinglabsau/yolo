<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EcrRepository implements Resource
{
    public function name(): string
    {
        return AwsLookups::ecrRepositoryName();
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        try {
            AwsLookups::ecrRepository();

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return AwsLookups::ecrRepository()['repositoryArn'];
    }

    public function create(): void
    {
        Aws::ecr()->createRepository([
            'repositoryName' => $this->name(),
            'imageScanningConfiguration' => ['scanOnPush' => true],
            'imageTagMutability' => 'MUTABLE',
            'tags' => Aws::tags($this->tags(), wrap: 'tags')['tags'],
        ]);

        Aws::ecr()->putLifecyclePolicy([
            'repositoryName' => $this->name(),
            'lifecyclePolicyText' => $this->lifecyclePolicy(),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseEcrTags($this->arn(), $this->tags());
    }

    public function lifecyclePolicy(): string
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
