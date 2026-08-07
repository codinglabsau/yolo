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
 * One Typesense node — a single-task ECS service on the env services cluster.
 * Three of these (typesense-0/1/2) form the Raft cluster; each is pinned to
 * one public subnet so the trio is AZ-spread by construction, and each
 * registers its task with its own Cloud Map service so peers address it by a
 * stable DNS name.
 *
 * Deploys stop-then-start (min 0 / max 100): a node's replacement must never
 * run beside it — two tasks behind one DNS name would split the Raft
 * identity — and a single node going away briefly is exactly what the quorum
 * exists to absorb. Deleted by the cluster's cascading teardown, never
 * individually.
 */
class TypesenseService implements Resource
{
    use ResolvesTags;

    /**
     * How long ECS ignores target-group health after a task starts. The check
     * itself is liveness (answers as soon as the API is up), but a replacement
     * node's port stays closed through its whole entrypoint boot gate — DNS
     * for itself and a peer to join — so the window covers a worst-case gate
     * plus image pull with room to spare. Generous is free here: the moment
     * the API answers 401s the check passes and the grace stops mattering.
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
            // One task-definition family is shared by all three nodes — the
            // image bakes the peer list and each node identifies itself by
            // matching a local interface against it.
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
                    // Public IP for the image pull — the env VPC routes through
                    // an internet gateway, not NAT. The SG admits nothing the
                    // rules don't open.
                    'assignPublicIp' => 'ENABLED',
                ],
            ],
            'serviceRegistries' => [
                ['registryArn' => (new TypesenseDiscoveryService($this->node))->arn()],
            ],
            // All the nodes register into the one search target group; its
            // health check is process liveness (see SearchTargetGroup), so a
            // replacement counts as healthy once its API answers, quorum or
            // not — readiness is the node sync step's roll gate.
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
     * Adopt the family's latest task-definition revision — how a version bump
     * or key rotation rolls a node. The caller sequences nodes and waits for
     * stability between them. The grace period rides along so a service
     * created under an older, tighter window picks the current one up on its
     * next roll.
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

    /**
     * Node n lives in subnet n — with the three public subnets in distinct
     * AZs, the quorum is AZ-spread deterministically.
     */
    protected function subnetId(): string
    {
        $subnetIds = PublicSubnet::ids();

        return $subnetIds[$this->node % count($subnetIds)];
    }
}
