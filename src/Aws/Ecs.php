<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Thin per-service wrapper around the ECS SDK client. Each method takes the
 * identifier it needs (no manifest reads, no keyedResourceName) and returns
 * the parsed AWS response shape — or throws ResourceDoesNotExistException
 * when the resource isn't found.
 */
class Ecs
{
    public static function cluster(string $name): array
    {
        $clusters = Aws::ecs()->describeClusters([
            'clusters' => [$name],
        ])['clusters'];

        foreach ($clusters as $cluster) {
            if ($cluster['status'] !== 'INACTIVE') {
                return $cluster;
            }
        }

        throw new ResourceDoesNotExistException("Could not find ECS cluster $name");
    }

    public static function service(string $cluster, string $name): array
    {
        try {
            $services = Aws::ecs()->describeServices([
                'cluster' => $cluster,
                'services' => [$name],
            ])['services'];
        } catch (AwsException) {
            // Surfaces ClusterNotFoundException (cold account), ServiceNotFoundException,
            // and any other SDK error as the project's standard not-found signal so the
            // calling resource's catch can decide dry-run vs create.
            throw new ResourceDoesNotExistException("Could not find ECS service $name");
        }

        foreach ($services as $service) {
            if ($service['status'] !== 'INACTIVE') {
                return $service;
            }
        }

        throw new ResourceDoesNotExistException("Could not find ECS service $name");
    }

    /**
     * Running task ARNs for a service. A missing service (e.g. a group that
     * isn't its own service yet) yields an empty list rather than throwing —
     * the caller decides what "no tasks here" means.
     *
     * @return array<int, string>
     */
    public static function runningTasks(string $cluster, string $service): array
    {
        try {
            return Aws::ecs()->listTasks([
                'cluster' => $cluster,
                'serviceName' => $service,
                'desiredStatus' => 'RUNNING',
            ])['taskArns'];
        } catch (AwsException) {
            return [];
        }
    }

    public static function taskDefinition(string $family): array
    {
        try {
            return Aws::ecs()->describeTaskDefinition([
                'taskDefinition' => $family,
            ])['taskDefinition'];
        } catch (AwsException) {
            throw new ResourceDoesNotExistException("Could not find ECS task definition $family");
        }
    }
}
