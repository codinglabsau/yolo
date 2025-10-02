<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Sns;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesSns
{
    public static function alarmTopic(): array
    {
        return static::topicByName(Helpers::keyedResourceName(exclusive: false));
    }

    public static function mediaConvertTopic(): array
    {
        return static::topicByName(Helpers::keyedResourceName(Sns::MEDIA_CONVERT_TOPIC));
    }

    public static function topicByName(string $name): array
    {
        $topics = Aws::sns()->listTopics();

        foreach ($topics['Topics'] as $topic) {
            if (Str::afterLast($topic['TopicArn'], ':') === $name) {
                return $topic;
            }
        }

        throw new ResourceDoesNotExistException("Could not find SNS topic with name $name");
    }
}
