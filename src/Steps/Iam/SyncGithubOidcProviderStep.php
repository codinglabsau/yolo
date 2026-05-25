<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\GithubOidcProvider;

class SyncGithubOidcProviderStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        // Only provision GitHub OIDC infra when this app is actually on GitHub.
        if (Helpers::githubRepository() === null) {
            return StepResult::SKIPPED;
        }

        $provider = new GithubOidcProvider();

        // Account-level singleton: shared across every environment and app, so it
        // is never re-tagged or reconciled — if it already exists it is done.
        if ($provider->exists()) {
            return StepResult::SYNCED;
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        $provider->create();

        return StepResult::CREATED;
    }
}
