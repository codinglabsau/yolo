<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesSns
{
    public static function topic(string $topicName): array
    {
        $topics = Aws::sns()->listTopics();

        foreach ($topics['Topics'] as $topic) {
            if (Str::afterLast($topic['TopicArn'], ':') === $topicName) {
                return $topic;
            }
        }

        throw new ResourceDoesNotExistException("Could not find SNS topic with name $topicName");
    }
}
