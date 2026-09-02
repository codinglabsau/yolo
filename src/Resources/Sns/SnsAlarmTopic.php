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
 * Suffixed `alarms` so the name can't collide with a bare-keyed resource from another
 * deployment generation. Subscriptions are not YOLO-managed.
 */
class SnsAlarmTopic implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName('alarms');
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

    public function delete(): void
    {
        try {
            Aws::sns()->deleteTopic(['TopicArn' => $this->arn()]);
        } catch (ResourceDoesNotExistException) {
            // Removed between exists() and here — nothing left to do.
        } catch (SnsException $e) {
            if ($e->getAwsErrorCode() === 'NotFound') {
                return;
            }

            throw $e;
        }
    }
}
