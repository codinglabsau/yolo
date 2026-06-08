<?php

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
