<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Same-account: YOLO both requests and accepts, and configuration sync re-accepts
 * on every run so an interrupted create self-heals. Routing is a separate step
 * (SyncVpcPeeringRoutesStep) and DNS resolution is deliberately last
 * (SyncVpcPeeringDnsStep) — it's the switch that sends traffic across the bridge,
 * so it must not flip until every route exists.
 */
class VpcPeeringConnection implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function __construct(public readonly string $peerVpcId) {}

    public function name(): string
    {
        return $this->keyedName("peering-{$this->peerVpcId}");
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        return Ec2::livePeeringConnection($this->name()) !== null;
    }

    public function arn(): string
    {
        $connection = Ec2::livePeeringConnection($this->name());

        if ($connection === null) {
            throw new ResourceDoesNotExistException("Could not find a live peering connection {$this->name()}");
        }

        return $connection['VpcPeeringConnectionId'];
    }

    public function create(): void
    {
        Aws::ec2()->createVpcPeeringConnection([
            'VpcId' => (new Vpc())->arn(),
            'PeerVpcId' => $this->peerVpcId,
            'TagSpecifications' => [
                ['ResourceType' => 'vpc-peering-connection', ...Aws::tags($this->tags())],
            ],
        ]);

        // The request needs a beat to reach pending-acceptance, so the accept rides the reconcile.
        $this->synchroniseConfiguration();
    }

    /**
     * DNS resolution is deliberately NOT reconciled here — see the class docblock.
     *
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $connection = Ec2::livePeeringConnection($this->name());

        if ($connection === null) {
            return [];
        }

        $changes = [];
        $connectionId = $connection['VpcPeeringConnectionId'];
        $status = $connection['Status']['Code'] ?? '';

        if ($status !== 'active') {
            $changes[] = Change::make('status', $status, 'active');

            if ($apply) {
                $this->acceptWhenPending($connectionId);
            }
        }

        return $changes;
    }

    /** An absent connection reads false — on a greenfield plan pass the enable is pending, not done. */
    public function dnsResolutionEnabled(): bool
    {
        $connection = Ec2::livePeeringConnection($this->name());

        return (bool) ($connection['RequesterVpcInfo']['PeeringOptions']['AllowDnsResolutionFromRemoteVpc'] ?? false)
            && (bool) ($connection['AccepterVpcInfo']['PeeringOptions']['AllowDnsResolutionFromRemoteVpc'] ?? false);
    }

    public function enableDnsResolution(): void
    {
        $this->enableDnsResolutionWhenActive($this->arn());
    }

    /**
     * The return routes sync wrote into the peer's tables, matched strictly (env CIDR
     * AND this connection) so nothing else in tables YOLO doesn't manage is ever
     * touched. Sorted by table id so the teardown plan reads identically run to run.
     *
     * @return array<int, array{RouteTableId: string, DestinationCidrBlock: string}>
     */
    public function foreignReturnRoutes(): array
    {
        $connection = Ec2::livePeeringConnection($this->name());

        if ($connection === null) {
            return [];
        }

        try {
            $environmentCidrBlock = Ec2::vpc((new Vpc())->name())['CidrBlock'] ?? null;
        } catch (ResourceDoesNotExistException) {
            return [];
        }

        if ($environmentCidrBlock === null) {
            return [];
        }

        $connectionId = $connection['VpcPeeringConnectionId'];
        $foreignReturnRoutes = [];

        foreach (Ec2::vpcRouteTables($this->peerVpcId) as $peerRouteTable) {
            foreach ($peerRouteTable['Routes'] ?? [] as $route) {
                if (($route['VpcPeeringConnectionId'] ?? null) === $connectionId
                    && ($route['DestinationCidrBlock'] ?? null) === $environmentCidrBlock) {
                    $foreignReturnRoutes[] = [
                        'RouteTableId' => $peerRouteTable['RouteTableId'],
                        'DestinationCidrBlock' => $route['DestinationCidrBlock'],
                    ];
                }
            }
        }

        return $foreignReturnRoutes;
    }

    /**
     * Reverse of bring-up: DNS off first so workloads stop resolving across the
     * bridge before any route disappears, then the yolo-managed routes, then the
     * peer's return routes, then the connection.
     */
    public function delete(): void
    {
        $connection = Ec2::livePeeringConnection($this->name());

        if ($connection === null) {
            return;
        }

        $connectionId = $connection['VpcPeeringConnectionId'];

        $this->disableDnsResolution($connection);

        foreach ([new RouteTable(), new PrivateRouteTable()] as $environmentRouteTable) {
            try {
                $routeTable = Ec2::routeTable($environmentRouteTable->name());
            } catch (ResourceDoesNotExistException) {
                continue;
            }

            foreach ($routeTable['Routes'] ?? [] as $route) {
                if (($route['VpcPeeringConnectionId'] ?? null) === $connectionId && isset($route['DestinationCidrBlock'])) {
                    Aws::ec2()->deleteRoute([
                        'RouteTableId' => $routeTable['RouteTableId'],
                        'DestinationCidrBlock' => $route['DestinationCidrBlock'],
                    ]);
                }
            }
        }

        foreach ($this->foreignReturnRoutes() as $foreignReturnRoute) {
            Aws::ec2()->deleteRoute([
                'RouteTableId' => $foreignReturnRoute['RouteTableId'],
                'DestinationCidrBlock' => $foreignReturnRoute['DestinationCidrBlock'],
            ]);
        }

        try {
            Aws::ec2()->deleteVpcPeeringConnection(['VpcPeeringConnectionId' => $connectionId]);
        } catch (Ec2Exception $e) {
            if (str_starts_with($e->getAwsErrorCode() ?? '', 'InvalidVpcPeeringConnectionID')) {
                return;
            }

            throw $e;
        }
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }

    /** The create returns before the request reaches pending-acceptance. */
    protected function acceptWhenPending(string $connectionId, int $maxAttempts = 6, int $sleepSeconds = 5): void
    {
        $attempt = 0;

        while (true) {
            try {
                Aws::ec2()->acceptVpcPeeringConnection(['VpcPeeringConnectionId' => $connectionId]);

                return;
            } catch (Ec2Exception $exception) {
                $attempt++;

                if ($attempt >= $maxAttempts || $exception->getAwsErrorCode() !== 'InvalidStateTransition') {
                    throw $exception;
                }

                sleep($sleepSeconds);
            }
        }
    }

    /** Options are only settable once active; a just-accepted connection is still provisioning. */
    protected function enableDnsResolutionWhenActive(string $connectionId, int $maxAttempts = 6, int $sleepSeconds = 5): void
    {
        $attempt = 0;

        while (true) {
            try {
                Aws::ec2()->modifyVpcPeeringConnectionOptions([
                    'VpcPeeringConnectionId' => $connectionId,
                    'RequesterPeeringConnectionOptions' => ['AllowDnsResolutionFromRemoteVpc' => true],
                    'AccepterPeeringConnectionOptions' => ['AllowDnsResolutionFromRemoteVpc' => true],
                ]);

                return;
            } catch (Ec2Exception $exception) {
                $attempt++;

                if ($attempt >= $maxAttempts || ! in_array($exception->getAwsErrorCode(), ['InvalidVpcPeeringConnectionState.NotActive', 'IncorrectState'], true)) {
                    throw $exception;
                }

                sleep($sleepSeconds);
            }
        }
    }

    /**
     * Options are only settable on an active connection — anything else has nothing to switch off.
     *
     * @param  array<string, mixed>  $connection
     */
    protected function disableDnsResolution(array $connection): void
    {
        if (($connection['Status']['Code'] ?? '') !== 'active') {
            return;
        }

        Aws::ec2()->modifyVpcPeeringConnectionOptions([
            'VpcPeeringConnectionId' => $connection['VpcPeeringConnectionId'],
            'RequesterPeeringConnectionOptions' => ['AllowDnsResolutionFromRemoteVpc' => false],
            'AccepterPeeringConnectionOptions' => ['AllowDnsResolutionFromRemoteVpc' => false],
        ]);
    }
}
