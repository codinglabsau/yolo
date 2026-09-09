<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Resources\Iam\UsersGroup;
use Codinglabs\Yolo\Aws\ResourceGroupsTaggingApi;

/**
 * Shared by every account-scoped teardown step: an account-shared resource
 * (the GitHub OIDC provider, the {@see UsersGroup} baseline) goes only with
 * the last environment, and never on a guess — if the tag scan can't prove
 * the account is empty, the caller keeps the resource.
 */
trait GuardsLastEnvironment
{
    /**
     * @return array<int, string>
     */
    protected function otherEnvironments(): array
    {
        return collect(ResourceGroupsTaggingApi::getResources([['Key' => 'yolo:environment']]))
            ->map(fn (array $resource): array => Aws::flattenTags($resource['Tags']))
            ->map(fn (array $tags): ?string => $tags['yolo:environment'] ?? null)
            ->filter()
            ->reject(fn (string $environment): bool => $environment === Helpers::environment())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
