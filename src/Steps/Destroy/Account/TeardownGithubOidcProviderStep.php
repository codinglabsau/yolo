<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Account;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Concerns\RecordsWarnings;
use Codinglabs\Yolo\Aws\ResourceGroupsTaggingApi;
use Codinglabs\Yolo\Resources\Iam\GithubOidcProvider;

/**
 * The provider is account-shared (every environment's deployer roles federate
 * through it), so it goes only with the last environment — and never on a
 * guess: if the tag scan can't prove the account is empty, it's kept.
 */
class TeardownGithubOidcProviderStep implements Step
{
    use RecordsChanges;
    use RecordsWarnings;

    public function __invoke(array $options): StepResult
    {
        $provider = new GithubOidcProvider();

        if (! $provider->exists()) {
            return StepResult::SKIPPED;
        }

        try {
            $others = $this->otherEnvironments();
        } catch (\Throwable $exception) {
            $this->recordWarning(sprintf(
                'Kept the account-shared GitHub OIDC provider — could not verify whether other environments exist (%s). It is reclaimed only once that is confirmed.',
                $exception->getMessage(),
            ));

            return StepResult::SKIPPED;
        }

        if ($others !== []) {
            $this->recordWarning(sprintf(
                'Kept the account-shared GitHub OIDC provider — other environments still exist (%s). It is reclaimed only when the last environment is torn down.',
                implode(', ', $others),
            ));

            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make($provider->name(), 'provisioned', null));

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $provider->delete();

        return StepResult::DELETED;
    }

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
