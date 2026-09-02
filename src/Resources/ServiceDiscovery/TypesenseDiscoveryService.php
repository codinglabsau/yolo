<?php

namespace Codinglabs\Yolo\Resources\ServiceDiscovery;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Aws\ServiceDiscovery as ServiceDiscoveryApi;

/**
 * One Cloud Map service per node (not one for the cluster) because Raft peers must
 * address each node individually; a replaced task re-resolves within the 10s TTL.
 */
class TypesenseDiscoveryService implements Deletable, Resource
{
    use ResolvesTags;

    public function __construct(protected int $node) {}

    public function name(): string
    {
        return sprintf('typesense-%d', $this->node);
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
        return $this->current()['Arn'];
    }

    /** CreateService is synchronous (unlike namespace mutations). */
    public function create(): void
    {
        Aws::serviceDiscovery()->createService([
            'Name' => $this->name(),
            'NamespaceId' => (new PrivateDnsNamespace())->id(),
            'DnsConfig' => [
                'RoutingPolicy' => 'MULTIVALUE',
                // Peers re-resolve a replaced node quickly; Raft tolerates the gap.
                'DnsRecords' => [['Type' => 'A', 'TTL' => 10]],
            ],
            // ECS owns instance health; Route 53 checks can't see private IPs.
            'HealthCheckCustomConfig' => ['FailureThreshold' => 1],
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseServiceDiscoveryTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Cloud Map refuses to delete a service while its ECS instance is still
     * deregistering, so wait that window out.
     */
    public function delete(): void
    {
        $id = $this->current()['Id'];
        $deadline = time() + 120;

        do {
            try {
                Aws::serviceDiscovery()->deleteService(['Id' => $id]);

                return;
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceInUse' || time() >= $deadline) {
                    throw $e;
                }

                sleep(5);
            }
        } while (true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function current(): array
    {
        return ServiceDiscoveryApi::service((new PrivateDnsNamespace())->id(), $this->name());
    }
}
