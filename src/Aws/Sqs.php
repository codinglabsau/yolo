<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Sqs
{
    public static function queue(string $name): array
    {
        foreach (Aws::sqs()->listQueues()['QueueUrls'] ?? [] as $queueUrl) {
            if (Str::afterLast($queueUrl, '/') === $name) {
                // listQueues returns URLs only
                return [
                    'QueueUrl' => $queueUrl,
                    ...Aws::sqs()->getQueueAttributes([
                        'QueueUrl' => $queueUrl,
                        'AttributeNames' => ['All'],
                    ])->toArray(),
                ];
            }
        }

        throw new ResourceDoesNotExistException("Could not find SQS queue $name");
    }

    /**
     * Null when the queue doesn't exist, so `yolo status` degrades on a
     * not-yet-provisioned queue instead of hard-erroring.
     */
    public static function approximateMessages(string $name): ?int
    {
        try {
            $attributes = static::queue($name)['Attributes'] ?? [];
        } catch (ResourceDoesNotExistException) {
            return null;
        }

        return isset($attributes['ApproximateNumberOfMessages'])
            ? (int) $attributes['ApproximateNumberOfMessages']
            : null;
    }
}
