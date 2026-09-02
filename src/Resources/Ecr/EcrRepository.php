<?php

namespace Codinglabs\Yolo\Resources\Ecr;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ecr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ecr\Exception\EcrException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Env-scoped (`yolo-{env}-{app}`), NOT bare `{app}`: the image is env-specific by
 * construction (APP_VERSION/ASSET_URL baked at build), so a shared repo gains no
 * promotion — only collisions on `:latest`, `--cache-from` and the retention window,
 * and a deployer free to overwrite a sibling env's images.
 */
class EcrRepository implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName();
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            Ecr::repository($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ecr::repository($this->name())['repositoryArn'];
    }

    public function uri(): string
    {
        return sprintf(
            '%s.dkr.ecr.%s.amazonaws.com/%s',
            Manifest::get('account-id'),
            Manifest::get('region'),
            $this->name(),
        );
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

    /** `force` is required — a repository holding images can't be removed otherwise. */
    public function delete(): void
    {
        try {
            Aws::ecr()->deleteRepository([
                'repositoryName' => $this->name(),
                'force' => true,
            ]);
        } catch (EcrException $e) {
            if ($e->getAwsErrorCode() === 'RepositoryNotFoundException') {
                return;
            }

            throw $e;
        }
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEcrTags($this->arn(), $this->tags(), $apply);
    }

    public function lifecyclePolicy(): string
    {
        return json_encode([
            'rules' => [
                [
                    'rulePriority' => 1,
                    'description' => 'Expire untagged images after 7 days',
                    'selection' => [
                        'tagStatus' => 'untagged',
                        'countType' => 'sinceImagePushed',
                        'countUnit' => 'days',
                        'countNumber' => 7,
                    ],
                    'action' => ['type' => 'expire'],
                ],
                [
                    'rulePriority' => 2,
                    'description' => 'Keep last 30 tagged images',
                    'selection' => [
                        'tagStatus' => 'any',
                        'countType' => 'imageCountMoreThan',
                        'countNumber' => 30,
                    ],
                    'action' => ['type' => 'expire'],
                ],
            ],
        ]);
    }
}
