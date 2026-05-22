<?php

namespace Codinglabs\Yolo\Concerns;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Resource;

/**
 * Generic create-or-sync orchestration for steps backed by a Resource.
 * Steps with extra gating (cert state, manifest predicates, ingress rules)
 * keep their orchestration but delegate identity / create / tag-sync here.
 */
trait SynchronisesResource
{
    protected function syncResource(Resource $resource, array $options): StepResult
    {
        if ($resource->exists()) {
            if (! Arr::get($options, 'dry-run')) {
                $resource->synchroniseTags();
            }

            return StepResult::SYNCED;
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        $resource->create();

        return StepResult::CREATED;
    }
}
