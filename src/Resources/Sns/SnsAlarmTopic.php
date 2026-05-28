<?php

namespace Codinglabs\Yolo\Resources\Sns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Sns;
use Codinglabs\Yolo\Enums\Scope;
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
        return $this->keyedName();
    }

    public function scope(): Scope
    {
        return Scope::Env;
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

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseSnsTags($this->arn(), $this->tags(), $apply);
    }
}
