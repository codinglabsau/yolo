<?php

namespace Codinglabs\Yolo\Steps\Tenant;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncHostedZoneStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::hostedZone($this->config['apex']);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::route53()->createHostedZone([
                    'CallerReference' => Str::uuid(),
                    'Name' => $this->config['apex'],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
