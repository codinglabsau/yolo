<?php

namespace Codinglabs\Yolo\Steps\Recording;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

use function Laravel\Prompts\note;

class SyncIvsRecordingConfigurationStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsRecordingWebhookUrl()) {
            return StepResult::SKIPPED;
        }

        $bucket = SyncIvsRecordingBucketStep::bucketName();
        $name = Helpers::keyedResourceName('ivs-recording');

        $response = Aws::ivs()->listRecordingConfigurations();
        $all = $response['recordingConfigurations'];
        while ($nextToken = $response['nextToken'] ?? null) {
            $response = Aws::ivs()->listRecordingConfigurations(['nextToken' => $nextToken]);
            $all = array_merge($all, $response['recordingConfigurations']);
        }
        $existing = collect($all)->first(fn ($config) => Arr::get($config, 'destinationConfiguration.s3.bucketName') === $bucket);

        if ($existing) {
            note(sprintf('IVS RecordingConfiguration ARN: %s', $existing['arn']));
            note(sprintf('Set AWS_IVS_RECORDING_CONFIGURATION_ARN=%s', $existing['arn']));

            return StepResult::SYNCED;
        }

        if (! Arr::get($options, 'dry-run')) {
            $result = Aws::ivs()->createRecordingConfiguration([
                'name' => $name,
                'destinationConfiguration' => [
                    's3' => [
                        'bucketName' => $bucket,
                    ],
                ],
                'tags' => [
                    'yolo:environment' => Helpers::app('environment'),
                    'Name' => $name,
                ],
            ]);

            $arn = $result['recordingConfiguration']['arn'];
            $state = $result['recordingConfiguration']['state'] ?? null;

            // Poll until ACTIVE — creation can take a few seconds
            if ($state !== 'ACTIVE') {
                $attempts = 0;

                while ($attempts < 30) {
                    sleep(2);
                    $polled = Aws::ivs()->getRecordingConfiguration(['arn' => $arn]);
                    $state = $polled['recordingConfiguration']['state'] ?? null;

                    if ($state === 'ACTIVE') {
                        break;
                    }

                    $attempts++;
                }
            }

            if ($state !== 'ACTIVE') {
                note(sprintf('Warning: IVS RecordingConfiguration did not reach ACTIVE state (current: %s) — verify in AWS Console before setting the ARN', $state ?? 'unknown'));
            }

            note(sprintf('IVS RecordingConfiguration ARN: %s', $arn));
            note(sprintf('Set AWS_IVS_RECORDING_CONFIGURATION_ARN=%s', $arn));

            return StepResult::CREATED;
        }

        return StepResult::WOULD_CREATE;
    }
}
