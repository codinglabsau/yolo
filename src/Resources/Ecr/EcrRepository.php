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
 * The app's image repository, env-scoped (`yolo-{env}-{app}`) like every other
 * App resource — NOT bare `{app}`. The image is env-specific by construction
 * (`ConfigureEnvAndVersionStep` bakes `APP_VERSION`/`ASSET_URL` in at build), so
 * there is no "build once, promote across envs" to gain from a shared repo —
 * only collisions to avoid: two environments of the same app would clobber each
 * other's `:latest` (and so each other's `--cache-from`), share one 30-image
 * retention window, and the per-env-scoped deployer would be free to overwrite a
 * sibling env's images (DeployerPolicy scopes ECR to this repo's ARN).
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

    /**
     * Force-delete the repository and every image in it — `force` is required
     * because a repository holding images can't be removed otherwise. A missing
     * repository is the goal state, so its not-found code is swallowed.
     */
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
