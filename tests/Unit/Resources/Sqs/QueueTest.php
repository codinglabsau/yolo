<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\Sqs\Queue;

it('creates a queue with the retention and visibility attributes', function (): void {
    writeManifest(['queue-visibility-timeout' => 900]);

    $captured = [];
    bindMockSqsClient([], $captured);

    (new Queue('yolo-testing-my-app'))->create();

    expect($captured[0]['name'])->toBe('CreateQueue')
        ->and($captured[0]['args']['Attributes'])->toBe([
            'MessageRetentionPeriod' => '1209600',
            'VisibilityTimeout' => '900',
        ]);
});

it('reports an in-sync queue clean without writing', function (): void {
    $queueUrl = 'https://sqs.ap-southeast-2.amazonaws.com/1234/yolo-testing-my-app';
    writeManifest([]);

    $captured = [];
    bindMockSqsClient([
        'ListQueues' => new Result(['QueueUrls' => [$queueUrl]]),
        'GetQueueAttributes' => new Result(['Attributes' => [
            'MessageRetentionPeriod' => '1209600',
            'VisibilityTimeout' => '90',
        ]]),
    ], $captured);

    $changes = (new Queue('yolo-testing-my-app'))->synchroniseConfiguration();

    expect($changes)->toBe([])
        ->and(array_column($captured, 'name'))->not->toContain('SetQueueAttributes');
});

it('reconciles a drifted visibility timeout onto an existing queue', function (): void {
    $queueUrl = 'https://sqs.ap-southeast-2.amazonaws.com/1234/yolo-testing-my-app';
    writeManifest(['queue-visibility-timeout' => 900]);

    $captured = [];
    bindMockSqsClient([
        'ListQueues' => new Result(['QueueUrls' => [$queueUrl]]),
        'GetQueueAttributes' => new Result(['Attributes' => [
            'MessageRetentionPeriod' => '1209600',
            'VisibilityTimeout' => '30',
        ]]),
    ], $captured);

    $changes = (new Queue('yolo-testing-my-app'))->synchroniseConfiguration();

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->attribute)->toBe('VisibilityTimeout')
        ->and($changes[0]->from)->toBe('30')
        ->and($changes[0]->to)->toBe('900');

    $setAttributes = array_values(array_filter($captured, fn (array $call): bool => $call['name'] === 'SetQueueAttributes'));

    expect($setAttributes)->toHaveCount(1)
        ->and($setAttributes[0]['args']['QueueUrl'])->toBe($queueUrl)
        ->and($setAttributes[0]['args']['Attributes'])->toBe([
            'MessageRetentionPeriod' => '1209600',
            'VisibilityTimeout' => '900',
        ]);
});

it('computes visibility drift on the plan pass without writing', function (): void {
    $queueUrl = 'https://sqs.ap-southeast-2.amazonaws.com/1234/yolo-testing-my-app';
    writeManifest(['queue-visibility-timeout' => 900]);

    $captured = [];
    bindMockSqsClient([
        'ListQueues' => new Result(['QueueUrls' => [$queueUrl]]),
        'GetQueueAttributes' => new Result(['Attributes' => [
            'MessageRetentionPeriod' => '1209600',
            'VisibilityTimeout' => '30',
        ]]),
    ], $captured);

    $changes = (new Queue('yolo-testing-my-app'))->synchroniseConfiguration(apply: false);

    expect($changes)->toHaveCount(1)
        ->and(array_column($captured, 'name'))->not->toContain('SetQueueAttributes');
});

it('treats a queue created before visibility was managed as drifted', function (): void {
    $queueUrl = 'https://sqs.ap-southeast-2.amazonaws.com/1234/yolo-testing-my-app';
    writeManifest([]);

    $captured = [];
    bindMockSqsClient([
        'ListQueues' => new Result(['QueueUrls' => [$queueUrl]]),
        'GetQueueAttributes' => new Result(['Attributes' => [
            'MessageRetentionPeriod' => '1209600',
            // SQS reports its own default when the attribute was never set explicitly
            'VisibilityTimeout' => '30',
        ]]),
    ], $captured);

    $changes = (new Queue('yolo-testing-my-app'))->synchroniseConfiguration(apply: false);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->describe())->toBe('VisibilityTimeout: 30 → 90');
});
