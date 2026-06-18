<?php

namespace Codinglabs\Yolo\Resources\Sqs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Sqs;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Sqs\Exception\SqsException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * An SQS queue, addressed by its full name so the solo, tenant and landlord
 * steps share one resource. Messages are retained for 14 days. App-scoped, so it
 * carries the yolo:app owner tag for `yolo audit`.
 */
class Queue implements Deletable, Resource
{
    use ResolvesTags;

    public function __construct(protected string $queueName) {}

    public function name(): string
    {
        return $this->queueName;
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            Sqs::queue($this->queueName);

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Sqs::queue($this->queueName)['Attributes']['QueueArn'];
    }

    public function url(): string
    {
        return Sqs::queue($this->queueName)['QueueUrl'];
    }

    public function create(): void
    {
        Aws::sqs()->createQueue([
            'QueueName' => $this->queueName,
            'Attributes' => [
                'MessageRetentionPeriod' => '1209600', // 14 days
            ],
            ...Aws::tags($this->tags(), wrap: 'tags', associative: true),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseSqsTags($this->url(), $this->tags(), $apply);
    }

    /**
     * Delete the queue. DeleteQueue purges any in-flight/retained messages as
     * part of the same call, so there is nothing to drain first. A concurrent
     * removal (NonExistentQueue) is tolerated — the desired end state is reached.
     */
    public function delete(): void
    {
        try {
            Aws::sqs()->deleteQueue(['QueueUrl' => $this->url()]);
        } catch (SqsException $e) {
            if (str_contains((string) $e->getAwsErrorCode(), 'NonExistentQueue')) {
                return;
            }

            throw $e;
        }
    }
}
