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
 * Reclaims the account-shared GitHub OIDC provider — but only when no other
 * environment remains. The provider is account-scoped (one per account, federated
 * by every environment's deployer roles), so it's deleted only as the very last
 * act of tearing down the final environment. While any resource tagged
 * yolo:environment=<other> still exists, it's deliberately kept and named in the
 * teardown's refusal summary, never deleted.
 *
 * It fails safe: if the "are there other environments?" tag scan can't be
 * completed, the provider is kept, never deleted on a guess — an account-shared
 * resource is only reclaimed when its emptiness is positively confirmed.
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
            // Fail safe: can't prove this is the last environment ⇒ keep the
            // account-shared provider rather than risk pulling it from a live env.
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
     * Every environment other than this one that still has tagged resources,
     * derived from the live yolo:environment tags across the account.
     *
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
