<?php

namespace Codinglabs\Yolo\Steps\Compute;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncElasticTranscoderPresetStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (Manifest::get('aws.transcoder') === null) {
            return StepResult::SKIPPED;
        }

        try {
            AwsResources::elasticTranscoderPreset();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::elasticTranscoder()->createPreset([
                    'Name' => Helpers::keyedResourceName(),
                    'Description' => Helpers::environment() . ' transcoding preset',
                    'Container' => 'mp4',
                    'Audio' => [
                        'Codec' => 'AAC',
                        'SampleRate' => '44100',
                        'BitRate' => '160',
                        'Channels' => '2',
                        'CodecOptions' => [
                            'Profile' => 'AAC-LC',
                        ],
                    ],
                    'Video' => [
                        'Codec' => 'H.264',
                        'CodecOptions' => [
                            'ColorSpaceConversionMode' => 'None',
                            'InterlacedMode' => 'Progressive',
                            'Level' => '3.1',
                            'MaxReferenceFrames' => '3',
                            'Profile' => 'main',
                        ],

                        'KeyframesMaxDist' => '90',
                        'FixedGOP' => 'false',
                        'BitRate' => '2200',
                        'FrameRate' => '30',
                        'MaxWidth' => '1280',
                        'MaxHeight' => '720',
                        'DisplayAspectRatio' => 'auto',
                        'SizingPolicy' => 'ShrinkToFit',
                        'PaddingPolicy' => 'NoPad',
                    ],
                    'Thumbnails' => [
                        'Format' => 'png',
                        'Interval' => '10',
                        'MaxWidth' => '1280',
                        'MaxHeight' => '720',
                        'SizingPolicy' => 'ShrinkToFit',
                        'PaddingPolicy' => 'NoPad',
                    ],
                    // note: Elastic Transcoder does not appear to support tagging
                    //                    'TagSpecifications' => [
                    //                        [
                    //                            'ResourceType' => 'preset',
                    //                            ...Aws::tags([
                    //                                'Name' => Helpers::keyedResourceName(),
                    //                            ]),
                    //                        ],
                    //                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
