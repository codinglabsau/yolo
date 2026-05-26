<?php

namespace Codinglabs\Yolo\Resources\Network;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Sns;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared SNS topic that CloudWatch alarms (e.g. queue backlogs) publish to.
 */
class SnsAlarmTopic implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Helpers::keyedResourceName(exclusive: false);
    }

    public function exists(): bool
    {
        try {
            Sns::topic($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Sns::topic($this->name())['TopicArn'];
    }

    public function create(): void
    {
        Aws::sns()->createTopic([
            'Name' => $this->name(),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseSnsTags($this->arn(), $this->tags());
    }
}
