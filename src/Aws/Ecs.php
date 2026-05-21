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
    /** @var array<string, array<string, mixed>> */
    protected static array $clusters = [];

    /** @var array<string, array<string, mixed>> */
    protected static array $services = [];

    /** @var array<string, array<string, mixed>> */
    protected static array $taskDefinitions = [];

    public static function cluster(string $name, bool $refresh = false): array
    {
        if (! $refresh && isset(static::$clusters[$name])) {
            return static::$clusters[$name];
        }

        $clusters = Aws::ecs()->describeClusters([
            'clusters' => [$name],
        ])['clusters'];

        foreach ($clusters as $cluster) {
            if ($cluster['status'] !== 'INACTIVE') {
                return static::$clusters[$name] = $cluster;
            }
        }

        throw new ResourceDoesNotExistException("Could not find ECS cluster $name");
    }

    public static function service(string $cluster, string $name, bool $refresh = false): array
    {
        $key = "$cluster::$name";

        if (! $refresh && isset(static::$services[$key])) {
            return static::$services[$key];
        }

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
                return static::$services[$key] = $service;
            }
        }

        throw new ResourceDoesNotExistException("Could not find ECS service $name");
    }

    public static function taskDefinition(string $family, bool $refresh = false): array
    {
        if (! $refresh && isset(static::$taskDefinitions[$family])) {
            return static::$taskDefinitions[$family];
        }

        try {
            return static::$taskDefinitions[$family] = Aws::ecs()->describeTaskDefinition([
                'taskDefinition' => $family,
            ])['taskDefinition'];
        } catch (AwsException) {
            throw new ResourceDoesNotExistException("Could not find ECS task definition $family");
        }
    }
}
