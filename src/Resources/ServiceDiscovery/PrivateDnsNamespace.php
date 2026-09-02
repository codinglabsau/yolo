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
 * Stable in-VPC addresses for env-shared service tasks, so Raft peers survive task
 * replacement. Namespace mutations are asynchronous; create() blocks on the operation.
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
     * AWS refuses to delete a namespace with services in it. The node instances
     * deregister eventually after the cluster teardown stops the tasks, so each
     * service delete is retried past the transient ResourceInUse.
     */
    public function delete(): void
    {
        $namespaceId = $this->id();

        foreach (ServiceDiscoveryApi::services($namespaceId) as $service) {
            ServiceDiscoveryApi::deleteServiceWhenDrained($service['Id']);
        }

        $operationId = Aws::serviceDiscovery()->deleteNamespace(['Id' => $namespaceId])['OperationId'];

        ServiceDiscoveryApi::waitForOperation($operationId);
    }
}
