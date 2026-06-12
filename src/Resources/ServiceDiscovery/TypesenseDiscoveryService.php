<?php

namespace Codinglabs\Yolo\Resources\ServiceDiscovery;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Aws\ServiceDiscovery as ServiceDiscoveryApi;

/**
 * One node's stable DNS name — typesense-{n}.{env}.internal. A Cloud Map
 * service per node (not one service for the cluster) because Raft peers must
 * address each node individually; the ECS node service registers its task's
 * ENI here, so a replaced task re-resolves within the record's 10s TTL.
 * Deleted by the namespace's cascading teardown, never individually.
 */
class TypesenseDiscoveryService implements Resource
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

    /**
     * CreateService is synchronous (unlike namespace mutations) — the service
     * is queryable immediately.
     */
    public function create(): void
    {
        Aws::serviceDiscovery()->createService([
            'Name' => $this->name(),
            'NamespaceId' => (new PrivateDnsNamespace())->id(),
            'DnsConfig' => [
                'RoutingPolicy' => 'MULTIVALUE',
                // 10s TTL: a replaced node's peers re-resolve quickly without
                // hammering the resolver — Raft tolerates the gap.
                'DnsRecords' => [['Type' => 'A', 'TTL' => 10]],
            ],
            // ECS owns instance health via its own lifecycle — a custom health
            // config (rather than Route 53 checks, which can't see private IPs).
            'HealthCheckCustomConfig' => ['FailureThreshold' => 1],
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseServiceDiscoveryTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * @return array<string, mixed>
     */
    protected function current(): array
    {
        return ServiceDiscoveryApi::service((new PrivateDnsNamespace())->id(), $this->name());
    }
}
