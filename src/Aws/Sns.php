<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Sns
{
    public static function topic(string $name): array
    {
        foreach (Aws::sns()->listTopics()['Topics'] ?? [] as $topic) {
            if (Str::afterLast($topic['TopicArn'], ':') === $name) {
                return $topic;
            }
        }

        throw new ResourceDoesNotExistException("Could not find SNS topic $name");
    }
}
