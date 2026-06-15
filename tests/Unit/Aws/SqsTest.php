<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Aws\Sqs;

it('reads the approximate visible-message backlog for a queue', function (): void {
    $captured = [];
    bindMockSqsClient([
        'ListQueues' => new Result(['QueueUrls' => ['https://sqs.ap-southeast-2.amazonaws.com/1234/yolo-testing-my-app']]),
        'GetQueueAttributes' => new Result(['Attributes' => ['ApproximateNumberOfMessages' => '42']]),
    ], $captured);

    expect(Sqs::approximateMessages('yolo-testing-my-app'))->toBe(42);
});

it('returns null for the backlog when the queue does not exist', function (): void {
    $captured = [];
    bindMockSqsClient(['ListQueues' => new Result(['QueueUrls' => []])], $captured);

    expect(Sqs::approximateMessages('yolo-testing-missing'))->toBeNull();
});
