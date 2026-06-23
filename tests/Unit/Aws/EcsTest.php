<?php

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Aws\Ecs\EcsClient;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

function bindMockEcsClient(MockHandler $mock): void
{
    Helpers::app()->instance('ecs', new EcsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * The error CreateService throws in the brief ELB→ECS consistency window after a
 * target group is wired onto the ALB listener — the message lives in the error
 * context (where getAwsErrorMessage reads it), exactly as the real SDK populates it.
 */
function ecsTargetGroupNotAttached(): AwsException
{
    $message = 'The target group with targetGroupArn arn:...:targetgroup/yolo-typesense-my-app/abc does not have an associated load balancer.';

    return new AwsException($message, new Command('CreateService'), [
        'code' => 'InvalidParameterException',
        'message' => $message,
    ]);
}

it('translates an AwsException to ResourceDoesNotExistException for service', function (): void {
    $mock = new MockHandler();
    $mock->append(new AwsException(
        'Cluster not found.',
        new Command('DescribeServices'),
        ['code' => 'ClusterNotFoundException'],
    ));

    bindMockEcsClient($mock);

    Ecs::service('yolo-testing-my-app', 'yolo-testing-my-app-web');
})->throws(ResourceDoesNotExistException::class, 'Could not find ECS service');

it('translates an AwsException to ResourceDoesNotExistException for taskDefinition', function (): void {
    $mock = new MockHandler();
    $mock->append(new AwsException(
        'Task definition family not found.',
        new Command('DescribeTaskDefinition'),
        ['code' => 'ClientException'],
    ));

    bindMockEcsClient($mock);

    Ecs::taskDefinition('yolo-testing-my-app-web');
})->throws(ResourceDoesNotExistException::class, 'Could not find ECS task definition');

it('retries CreateService until the target group attachment propagates', function (): void {
    $mock = new MockHandler();
    $mock->append(ecsTargetGroupNotAttached());
    $mock->append(ecsTargetGroupNotAttached());
    $mock->append(new Result([]));

    bindMockEcsClient($mock);

    Ecs::createServiceWhenTargetGroupAttached(['cluster' => 'yolo-testing', 'serviceName' => 'web'], maxAttempts: 5, sleepSeconds: 0);

    // All three queued responses consumed: it retried past both lag errors to the success.
    expect($mock)->toHaveCount(0);
});

it('gives up and rethrows after the maximum attempts', function (): void {
    $mock = new MockHandler();
    $mock->append(ecsTargetGroupNotAttached());
    $mock->append(ecsTargetGroupNotAttached());
    $mock->append(ecsTargetGroupNotAttached());

    bindMockEcsClient($mock);

    $run = fn () => Ecs::createServiceWhenTargetGroupAttached(['cluster' => 'yolo-testing', 'serviceName' => 'web'], maxAttempts: 3, sleepSeconds: 0);

    expect($run)->toThrow(AwsException::class);
    expect($mock)->toHaveCount(0);
});

it('rethrows an unrelated InvalidParameterException immediately without retrying', function (): void {
    $mock = new MockHandler();
    $mock->append(new AwsException('Subnets can not be blank.', new Command('CreateService'), [
        'code' => 'InvalidParameterException',
        'message' => 'Subnets can not be blank.',
    ]));
    $mock->append(new Result([]));

    bindMockEcsClient($mock);

    $run = fn () => Ecs::createServiceWhenTargetGroupAttached(['cluster' => 'yolo-testing', 'serviceName' => 'web'], maxAttempts: 5, sleepSeconds: 0);

    expect($run)->toThrow(AwsException::class);
    // The success response is left untouched — it failed fast on the first attempt.
    expect($mock)->toHaveCount(1);
});
