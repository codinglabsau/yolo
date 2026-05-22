<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesEcs
{
    protected static array $ecsCluster;

    protected static array $ecsService;

    protected static array $ecsTaskDefinition;

    public static function ecsClusterName(): string
    {
        return Manifest::get('ecs.cluster', Helpers::keyedResourceName(exclusive: true));
    }

    public static function ecsServiceName(): string
    {
        return Helpers::keyedResourceName('web', exclusive: true);
    }

    public static function ecsTaskFamily(): string
    {
        return Helpers::keyedResourceName('web', exclusive: true);
    }

    public static function ecsCluster(bool $refresh = false): array
    {
        if (! $refresh && isset(static::$ecsCluster)) {
            return static::$ecsCluster;
        }

        $clusters = Aws::ecs()->describeClusters([
            'clusters' => [static::ecsClusterName()],
        ])['clusters'];

        foreach ($clusters as $cluster) {
            if ($cluster['status'] !== 'INACTIVE') {
                static::$ecsCluster = $cluster;

                return $cluster;
            }
        }

        throw new ResourceDoesNotExistException(sprintf('Could not find ECS cluster %s', static::ecsClusterName()));
    }

    public static function ecsService(bool $refresh = false): array
    {
        if (! $refresh && isset(static::$ecsService)) {
            return static::$ecsService;
        }

        try {
            $services = Aws::ecs()->describeServices([
                'cluster' => static::ecsClusterName(),
                'services' => [static::ecsServiceName()],
            ])['services'];
        } catch (AwsException) {
            // Surfaces ClusterNotFoundException (cold account), ServiceNotFoundException,
            // and any other SDK error as the project's standard not-found signal so the
            // calling step's catch can decide dry-run vs create.
            throw new ResourceDoesNotExistException(sprintf('Could not find ECS service %s', static::ecsServiceName()));
        }

        foreach ($services as $service) {
            if ($service['status'] !== 'INACTIVE') {
                static::$ecsService = $service;

                return $service;
            }
        }

        throw new ResourceDoesNotExistException(sprintf('Could not find ECS service %s', static::ecsServiceName()));
    }

    public static function ecsTaskDefinition(bool $refresh = false): array
    {
        if (! $refresh && isset(static::$ecsTaskDefinition)) {
            return static::$ecsTaskDefinition;
        }

        try {
            $taskDefinition = Aws::ecs()->describeTaskDefinition([
                'taskDefinition' => static::ecsTaskFamily(),
            ])['taskDefinition'];
        } catch (AwsException $e) {
            throw new ResourceDoesNotExistException(sprintf('Could not find ECS task definition %s', static::ecsTaskFamily()));
        }

        static::$ecsTaskDefinition = $taskDefinition;

        return $taskDefinition;
    }
}
