<?php

namespace Codinglabs\Yolo\Resources\Ecr;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ecr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Env-scoped repository for the environment's Typesense image — the pinned
 * upstream base plus the baked-in admin key config, content-tagged by
 * version + key fingerprint so an unchanged pair never rebuilds. Env-scoped
 * (unlike the per-app repos named after the app) because the image is the
 * environment's, shared by all three nodes.
 */
class TypesenseRepository implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName('typesense');
    }

    public function scope(): Scope
    {
        return Scope::Env;
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
            'imageTagMutability' => 'IMMUTABLE',
            'tags' => Aws::tags($this->tags(), wrap: 'tags')['tags'],
        ]);

        Aws::ecr()->putLifecyclePolicy([
            'repositoryName' => $this->name(),
            'lifecyclePolicyText' => $this->lifecyclePolicy(),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEcrTags($this->arn(), $this->tags(), $apply);
    }

    public function delete(): void
    {
        Aws::ecr()->deleteRepository([
            'repositoryName' => $this->name(),
            'force' => true,
        ]);
    }

    /**
     * Content tags are immutable and few (one per version/key pair) — keep a
     * short tail for rollback and let anything older expire.
     */
    public function lifecyclePolicy(): string
    {
        return json_encode([
            'rules' => [
                [
                    'rulePriority' => 1,
                    'description' => 'Keep last 5 tagged images',
                    'selection' => [
                        'tagStatus' => 'any',
                        'countType' => 'imageCountMoreThan',
                        'countNumber' => 5,
                    ],
                    'action' => ['type' => 'expire'],
                ],
            ],
        ]);
    }
}
