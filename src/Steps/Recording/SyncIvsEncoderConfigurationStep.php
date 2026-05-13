<?php

namespace Codinglabs\Yolo\Steps\Recording;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

use function Laravel\Prompts\note;

class SyncIvsEncoderConfigurationStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsRealtimeRecordingEnabled()) {
            return StepResult::SKIPPED;
        }

        $name = Helpers::keyedResourceName('ivs-encoder');

        $response = Aws::ivsRealTime()->listEncoderConfigurations();
        $all = $response['encoderConfigurations'];
        while ($nextToken = $response['nextToken'] ?? null) {
            $response = Aws::ivsRealTime()->listEncoderConfigurations(['nextToken' => $nextToken]);
            $all = array_merge($all, $response['encoderConfigurations']);
        }

        $existing = collect($all)->first(fn ($config) => $config['name'] === $name);

        if ($existing) {
            note(sprintf('IVS EncoderConfiguration ARN: %s', $existing['arn']));
            note(sprintf('Set AWS_IVS_ENCODER_CONFIGURATION_ARN=%s', $existing['arn']));

            return StepResult::SYNCED;
        }

        if (! Arr::get($options, 'dry-run')) {
            $result = Aws::ivsRealTime()->createEncoderConfiguration([
                'name' => $name,
                'video' => [
                    'width' => 1280,
                    'height' => 720,
                    'framerate' => 30,
                    'bitrate' => 2500000,
                ],
                'tags' => [
                    'yolo:environment' => Helpers::app('environment'),
                    'Name' => $name,
                ],
            ]);

            $arn = $result['encoderConfiguration']['arn'];

            note(sprintf('IVS EncoderConfiguration ARN: %s', $arn));
            note(sprintf('Set AWS_IVS_ENCODER_CONFIGURATION_ARN=%s', $arn));

            return StepResult::CREATED;
        }

        return StepResult::WOULD_CREATE;
    }
}
