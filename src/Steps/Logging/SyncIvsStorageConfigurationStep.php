<?php

namespace Codinglabs\Yolo\Steps\Logging;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

use function Laravel\Prompts\note;

class SyncIvsStorageConfigurationStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsEnabled()) {
            return StepResult::SKIPPED;
        }

        $bucket = Manifest::get('aws.ivs.realtime_recording_bucket');

        if (! $bucket) {
            return StepResult::SKIPPED;
        }

        $name = Helpers::keyedResourceName('ivs-storage');

        $existing = collect(Aws::ivsRealTime()->listStorageConfigurations()['storageConfigurations'])
            ->first(fn ($config) => Arr::get($config, 's3.bucketName') === $bucket);

        if ($existing) {
            note(sprintf('IVS StorageConfiguration ARN: %s', $existing['arn']));
            note(sprintf('Set AWS_IVS_STORAGE_CONFIGURATION_ARN=%s', $existing['arn']));

            return StepResult::SYNCED;
        }

        if (! Arr::get($options, 'dry-run')) {
            $result = Aws::ivsRealTime()->createStorageConfiguration([
                'name' => $name,
                's3' => [
                    'bucketName' => $bucket,
                ],
                'tags' => [
                    'yolo:environment' => Helpers::app('environment'),
                    'Name' => $name,
                ],
            ]);

            $arn = $result['storageConfiguration']['arn'];

            note(sprintf('IVS StorageConfiguration ARN: %s', $arn));
            note(sprintf('Set AWS_IVS_STORAGE_CONFIGURATION_ARN=%s', $arn));

            return StepResult::CREATED;
        }

        return StepResult::WOULD_CREATE;
    }
}
