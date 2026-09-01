<?php

namespace Codinglabs\Yolo\Resources\Sqs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Sqs;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Sqs\Exception\SqsException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * An SQS queue, addressed by its full name so the solo, tenant and landlord
 * steps share one resource. Messages are retained for 14 days; visibility
 * timeout comes from the manifest (`queue-visibility-timeout`) and is
 * reconciled onto existing queues, so raising it reaches every queue on the
 * next sync. App-scoped, so it carries the yolo:app owner tag for `yolo audit`.
 */
class Queue implements Deletable, Resource, SynchronisesConfiguration
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
            'Attributes' => $this->desiredAttributes(),
            ...Aws::tags($this->tags(), wrap: 'tags', associative: true),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseSqsTags($this->url(), $this->tags(), $apply);
    }

    /**
     * Reconcile the managed attributes on an existing queue. SQS reports every
     * attribute as a string, so the desired map is string-valued and compared
     * strictly. Reads only this queue's own live state, so the plan pass is safe
     * before any sibling exists (the two-pass contract).
     *
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $queue = Sqs::queue($this->queueName);
        $live = $queue['Attributes'] ?? [];

        $changes = [];

        foreach ($this->desiredAttributes() as $attribute => $desired) {
            if (($live[$attribute] ?? null) !== $desired) {
                $changes[] = Change::make($attribute, $live[$attribute] ?? null, $desired);
            }
        }

        if ($changes !== [] && $apply) {
            Aws::sqs()->setQueueAttributes([
                'QueueUrl' => $queue['QueueUrl'],
                'Attributes' => $this->desiredAttributes(),
            ]);
        }

        return $changes;
    }

    /**
     * The queue attributes YOLO owns, on create and on reconcile alike.
     * Retention stays hardcoded (no consumer needs a knob); visibility is the
     * manifest's, because it has to track the app's job runtime — a message
     * re-delivered while its job is still running executes twice.
     *
     * @return array<string, string>
     */
    protected function desiredAttributes(): array
    {
        return [
            'MessageRetentionPeriod' => '1209600', // 14 days
            'VisibilityTimeout' => (string) Manifest::queueVisibilityTimeout(),
        ];
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
