<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesElasticTranscoder
{
    public static function elasticTranscoderPipeline(): array
    {
        $name = Helpers::keyedResourceName();
        $pipelines = Aws::elasticTranscoder()->listPipelines();

        foreach ($pipelines['Pipelines'] as $pipeline) {
            if ($pipeline['Name'] === $name) {
                return $pipeline;
            }
        }

        ResourceDoesNotExistException::make("Could not find Elastic Transcoder pipeline with name $name")
            ->suggest('sync:compute')
            ->throw();
    }

    public static function elasticTranscoderPreset(): array
    {
        $name = Helpers::keyedResourceName();
        $presets = Aws::elasticTranscoder()->listPresets();

        foreach ($presets['Presets'] as $preset) {
            if ($preset['Name'] === $name) {
                return $preset;
            }
        }

        ResourceDoesNotExistException::make("Could not find Elastic Transcoder preset with name $name")
            ->suggest('sync:compute')
            ->throw();
    }
}
