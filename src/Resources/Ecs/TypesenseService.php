<?php

namespace Codinglabs\Yolo\Resources\Ecs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Resources\ElbV2\SearchTargetGroup;
use Codinglabs\Yolo\Resources\Ec2\TypesenseSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ServiceDiscovery\TypesenseDiscoveryService;

/**
 * One Raft node: pinned to one public subnet (so the trio is AZ-spread by
 * construction) and registered with its own Cloud Map service so peers address it
 * by a stable DNS name. Deploys stop-then-start: two tasks behind one DNS name
 * would split the Raft identity, and a node briefly going away is exactly what the
 * quorum absorbs. Deleted by the cluster's cascading teardown, never individually.
 */
class TypesenseService implements Resource
{
    use ResolvesTags;

    /**
     * A replacement node's port stays closed through its whole entrypoint boot gate
     * (DNS for itself and a peer to join), so the window covers a worst-case gate
     * plus image pull. Generous is free: the moment the API answers the check passes.
     */
    public const int HEALTH_CHECK_GRACE_SECONDS = 600;

    public function __construct(protected int $node) {}

    public function node(): int
    {
        return $this->node;
    }

    public function name(): string
    {
        return $this->keyedName(sprintf('typesense-%d', $this->node));
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            $this->current();

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return $this->current()['serviceArn'];
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return Ecs::service((new ServicesCluster())->name(), $this->name());
    }

    public function create(): void
    {
        Aws::ecs()->createService([
            'cluster' => (new ServicesCluster())->name(),
            'serviceName' => $this->name(),
            // One family for all nodes — each identifies itself by matching a local interface against the baked peer list.
            'taskDefinition' => $this->taskDefinitionFamily(),
            'desiredCount' => 1,
            'launchType' => 'FARGATE',
            'deploymentConfiguration' => [
                'deploymentCircuitBreaker' => ['enable' => true, 'rollback' => true],
                'minimumHealthyPercent' => 0,
                'maximumPercent' => 100,
            ],
            'networkConfiguration' => [
                'awsvpcConfiguration' => [
                    'subnets' => [$this->subnetId()],
                    'securityGroups' => [(new TypesenseSecurityGroup())->arn()],
                    // For the image pull — the env VPC routes through an internet gateway, not NAT.
                    'assignPublicIp' => 'ENABLED',
                ],
            ],
            'serviceRegistries' => [
                ['registryArn' => (new TypesenseDiscoveryService($this->node))->arn()],
            ],
            // The target group's health check is liveness (see SearchTargetGroup), so a
            // replacement is healthy once its API answers — readiness is the node sync step's roll gate.
            'loadBalancers' => [
                [
                    'targetGroupArn' => (new SearchTargetGroup())->arn(),
                    'containerName' => 'typesense',
                    'containerPort' => Typesense::API_PORT,
                ],
            ],
            'healthCheckGracePeriodSeconds' => self::HEALTH_CHECK_GRACE_SECONDS,
            'tags' => Aws::ecsTags($this->tags()),
            'propagateTags' => 'SERVICE',
        ]);
    }

    /**
     * The caller sequences nodes and waits for stability between them. The grace
     * period rides along so a service created under an older window picks up the current one.
     */
    public function adoptLatestRevision(): void
    {
        Aws::ecs()->updateService([
            'cluster' => (new ServicesCluster())->name(),
            'service' => $this->name(),
            'taskDefinition' => $this->taskDefinitionFamily(),
            'healthCheckGracePeriodSeconds' => self::HEALTH_CHECK_GRACE_SECONDS,
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEcsTags($this->arn(), $this->tags(), $apply);
    }

    public function taskDefinitionFamily(): string
    {
        return $this->keyedName('typesense');
    }

    /** Node n lives in subnet n, so the quorum is AZ-spread deterministically. */
    protected function subnetId(): string
    {
        $subnetIds = PublicSubnet::ids();

        return $subnetIds[$this->node % count($subnetIds)];
    }
}
