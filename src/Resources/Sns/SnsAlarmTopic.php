<?php

namespace Codinglabs\Yolo\Resources\Sns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Sns;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Sns\Exception\SnsException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared SNS topic that CloudWatch alarms (e.g. queue backlogs) publish to.
 */
class SnsAlarmTopic implements Deletable, Resource
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

    /**
     * Teardown when the environment is torn down: delete the topic. Its alarm
     * subscriptions go with it, and DeleteTopic is idempotent on an
     * already-removed topic, but a concurrent removal (NotFound) is tolerated
     * defensively all the same.
     */
    public function delete(): void
    {
        try {
            Aws::sns()->deleteTopic(['TopicArn' => $this->arn()]);
        } catch (ResourceDoesNotExistException) {
            // arn() resolves the topic by listing; a concurrent delete that
            // removed it between exists() and here leaves nothing to do.
        } catch (SnsException $e) {
            if ($e->getAwsErrorCode() === 'NotFound') {
                return;
            }

            throw $e;
        }
    }
}
