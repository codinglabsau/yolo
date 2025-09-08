<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesSqs
{
    public static function queue(string $queueName): array
    {
        $queues = Aws::sqs()->listQueues();

        foreach ($queues['QueueUrls'] as $queueUrl) {
            if (Str::afterLast($queueUrl, '/') === $queueName) {
                // listQueues() returns only queue URLs, so
                // we need to query additional details.
                return [
                    'QueueUrl' => $queueUrl, // AWS does not have this
                    ...Aws::sqs()->getQueueAttributes([
                        'QueueUrl' => $queueUrl,
                        'AttributeNames' => ['All'],
                    ])->toArray(),
                ];
            }
        }

        throw new ResourceDoesNotExistException("Could not find queue with name $queueName");
    }
}
