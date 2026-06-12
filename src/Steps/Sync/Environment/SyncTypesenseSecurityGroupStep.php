<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\TypesenseSecurityGroup;
use Codinglabs\Yolo\Resources\Ec2\LoadBalancerSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The node tasks' security group and its baseline ingress: the search API
 * (8108) from the env ALB's security group, and Raft peering (8107)
 * node-to-node (self-referencing). Rules are reconciled additively, identified
 * by content — consuming apps' task-SG 8108 ingress is the app tier's to add,
 * the RDS-3306 pattern.
 */
class SyncTypesenseSecurityGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $state = Lifecycle::state(Service::TYPESENSE);

        if ($state === ServiceState::Retain) {
            return StepResult::SKIPPED;
        }

        if ($state === ServiceState::Teardown) {
            return $this->teardownResource(new TypesenseSecurityGroup(), $options);
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $group = new TypesenseSecurityGroup();

        $result = $this->syncResource($group, $options);

        if (! $group->exists()) {
            // Greenfield plan — the group's own create is pending, so both
            // rules are too. Record them so the apply pass runs this step.
            $this->recordChange(Change::make(sprintf('ingress %d/tcp from load balancer security group', Typesense::API_PORT), null, 'authorised'));
            $this->recordChange(Change::make(sprintf('ingress %d/tcp node-to-node', Typesense::PEERING_PORT), null, 'authorised'));

            return $result;
        }

        $groupId = $group->arn();

        $changed = $this->reconcileIngress(
            $groupId,
            Typesense::API_PORT,
            $this->loadBalancerSecurityGroupId(),
            'Search API from the load balancer',
            sprintf('ingress %d/tcp from load balancer security group', Typesense::API_PORT),
            $dryRun,
        );

        $changed = $this->reconcileIngress(
            $groupId,
            Typesense::PEERING_PORT,
            $groupId,
            'Raft peering node-to-node',
            sprintf('ingress %d/tcp node-to-node', Typesense::PEERING_PORT),
            $dryRun,
        ) || $changed;

        if ($changed && $result === StepResult::SYNCED) {
            return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
        }

        return $result;
    }

    /**
     * Additively ensure a "<port> from <source SG>" rule. A null source (the
     * ALB SG missing on a greenfield plan) records the pending rule without
     * resolving the sibling. Never revokes; identified by content.
     */
    protected function reconcileIngress(string $groupId, int $port, ?string $sourceGroupId, string $description, string $label, bool $dryRun): bool
    {
        if ($sourceGroupId === null) {
            $this->recordChange(Change::make($label, null, 'authorised (load balancer SG pending)'));

            return true;
        }

        $alreadyAuthorised = collect(Ec2::securityGroupRules($groupId))->contains(
            fn (array $rule): bool => ! ($rule['IsEgress'] ?? false)
                && ($rule['IpProtocol'] ?? null) === 'tcp'
                && ($rule['FromPort'] ?? null) === $port
                && ($rule['ReferencedGroupInfo']['GroupId'] ?? null) === $sourceGroupId
        );

        if ($alreadyAuthorised) {
            return false;
        }

        $this->recordChange(Change::make($label, null, $sourceGroupId));

        if (! $dryRun) {
            Aws::ec2()->authorizeSecurityGroupIngress([
                'GroupId' => $groupId,
                'IpPermissions' => [
                    [
                        'IpProtocol' => 'tcp',
                        'FromPort' => $port,
                        'ToPort' => $port,
                        'UserIdGroupPairs' => [
                            [
                                'GroupId' => $sourceGroupId,
                                'Description' => $description,
                            ],
                        ],
                    ],
                ],
            ]);
        }

        return true;
    }

    protected function loadBalancerSecurityGroupId(): ?string
    {
        try {
            return (new LoadBalancerSecurityGroup())->arn();
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
