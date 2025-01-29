<?php

namespace Codinglabs\Yolo\Steps\Standalone;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesDomainStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncHostedZoneStep implements ExecutesDomainStep
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::hostedZone(Manifest::apex());
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::route53()->createHostedZone([
                    'CallerReference' => Str::uuid(),
                    'Name' => Manifest::apex(),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
