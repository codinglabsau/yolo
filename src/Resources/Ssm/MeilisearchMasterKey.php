<?php

namespace Codinglabs\Yolo\Resources\Ssm;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ssm;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The shared Meilisearch master key — generated once per environment and stored
 * as a SecureString parameter. The Meilisearch task definition references it via
 * `secrets` (so the value never appears in a task definition), and the build
 * reads it to bake MEILISEARCH_KEY into a consuming app's env. Generated, never
 * declared: the key exists nowhere outside Parameter Store.
 */
class MeilisearchMasterKey implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName('meilisearch-master-key');
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            Ssm::parameter($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ssm::parameter($this->name())['ARN'];
    }

    /**
     * The decrypted key, read at build time to populate MEILISEARCH_KEY.
     */
    public function value(): string
    {
        return Ssm::parameter($this->name(), decrypt: true)['Value'];
    }

    public function create(): void
    {
        Aws::ssm()->putParameter([
            'Name' => $this->name(),
            'Description' => 'YOLO managed Meilisearch master key - shared by every app in this environment',
            'Type' => 'SecureString',
            'Value' => bin2hex(random_bytes(32)),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseSsmTags($this->name(), $this->tags(), $apply);
    }
}
