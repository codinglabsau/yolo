<?php

use Aws\Command;
use Aws\MockHandler;
use Aws\Ecs\EcsClient;
use Codinglabs\Yolo\Helpers;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\AwsLookups;
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

it('translates ClusterNotFoundException to ResourceDoesNotExistException for ecsService', function () {
    $mock = new MockHandler();
    $mock->append(new AwsException(
        'Cluster not found.',
        new Command('DescribeServices'),
        ['code' => 'ClusterNotFoundException'],
    ));

    bindMockEcsClient($mock);

    AwsLookups::ecsService(refresh: true);
})->throws(ResourceDoesNotExistException::class, 'Could not find ECS service');

it('translates ClusterNotFoundException to ResourceDoesNotExistException for ecsTaskDefinition', function () {
    $mock = new MockHandler();
    $mock->append(new AwsException(
        'Task definition family not found.',
        new Command('DescribeTaskDefinition'),
        ['code' => 'ClientException'],
    ));

    bindMockEcsClient($mock);

    AwsLookups::ecsTaskDefinition(refresh: true);
})->throws(ResourceDoesNotExistException::class, 'Could not find ECS task definition');
