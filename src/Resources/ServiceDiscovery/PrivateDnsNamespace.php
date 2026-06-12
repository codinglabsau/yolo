<?php

namespace Codinglabs\Yolo\Resources\ServiceDiscovery;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Aws\ServiceDiscovery as ServiceDiscoveryApi;

/**
 * The environment's private Cloud Map DNS namespace ({env}.internal) —
 * stable in-VPC addresses for env-shared service tasks, so Raft peers (and
 * later any private service endpoint) survive task replacement. Creation is
 * asynchronous on AWS's side; create() blocks on the operation so the next
 * step can resolve the namespace id.
 */
class PrivateDnsNamespace implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Typesense::namespaceName();
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            ServiceDiscoveryApi::privateNamespace($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ServiceDiscoveryApi::privateNamespace($this->name())['Arn'];
    }

    public function id(): string
    {
        return ServiceDiscoveryApi::privateNamespace($this->name())['Id'];
    }

    public function create(): void
    {
        $operationId = Aws::serviceDiscovery()->createPrivateDnsNamespace([
            'Name' => $this->name(),
            'Vpc' => (new Vpc())->arn(),
            ...Aws::tags($this->tags()),
        ])['OperationId'];

        ServiceDiscoveryApi::waitForOperation($operationId);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseServiceDiscoveryTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Teardown cascades: AWS refuses to delete a namespace with services in
     * it, so every discovery service is deleted first (their ECS-registered
     * instances deregistered when the node services died — the cluster's
     * teardown runs earlier in the declared order).
     */
    public function delete(): void
    {
        $namespaceId = $this->id();

        foreach (ServiceDiscoveryApi::services($namespaceId) as $service) {
            Aws::serviceDiscovery()->deleteService(['Id' => $service['Id']]);
        }

        $operationId = Aws::serviceDiscovery()->deleteNamespace(['Id' => $namespaceId])['OperationId'];

        ServiceDiscoveryApi::waitForOperation($operationId);
    }
}
