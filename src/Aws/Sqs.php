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
                // listQueues returns URLs only, so pull the attributes the
                // caller needs (QueueArn etc.) and keep the URL alongside them.
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
}
