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
 * A VPC peering connection from the environment's VPC to one declared peer —
 * the bridge to infrastructure outside the YOLO network (typically a database
 * mid-migration), declared via the env manifest `peering` list. Same-account:
 * YOLO both requests and accepts the connection. Configuration sync re-accepts
 * on every run, so an interrupted create self-heals. Routing is a separate
 * relationship concern (SyncVpcPeeringRoutesStep), and DNS resolution over the
 * peering is deliberately last (SyncVpcPeeringDnsStep) — it's the switch that
 * sends traffic across the bridge, so it must not flip until every route
 * exists.
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

        // Same-account, so accept immediately — the request needs a beat to
        // reach pending-acceptance, so the accept rides the same reconcile the
        // config sync uses on every later run.
        $this->synchroniseConfiguration();
    }

    /**
     * Reconcile the connection to accepted. The accept is eventually
     * consistent, so it's retried briefly; an interrupted create heals on the
     * next sync. DNS resolution is deliberately NOT part of this reconcile —
     * SyncVpcPeeringDnsStep flips it only after the routes step has written
     * every route, so nothing resolves across a bridge that can't route yet.
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

    /**
     * Whether DNS resolution over the peering is enabled in both directions on
     * the live connection. An absent connection reads false — on a greenfield
     * plan pass the enable is pending, not done.
     */
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
     * The return routes sync wrote into the peer VPC's route tables — the
     * foreign writes this connection carries, matched strictly: the
     * destination must be the environment's CIDR AND the target must be this
     * connection, so nothing else in tables YOLO doesn't manage is ever named
     * or touched. Sorted by table id (see Ec2::vpcRouteTables) so the teardown
     * plan reads identically run to run. Empty when the connection or the env
     * VPC is already gone.
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
     * Tear down the connection and everything sync wrote to ride on it, in
     * reverse order of the bring-up: DNS resolution off first (workloads stop
     * resolving across the bridge before any route disappears), then the
     * peering routes in the yolo-managed tables, then the return routes sync
     * wrote into the peer's tables ({@see foreignReturnRoutes} — nothing else
     * in the foreign tables is ever touched), and finally the connection
     * itself. A concurrent removal is tolerated.
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

    /**
     * Accept the request, retrying while it's still initiating — the create
     * returns before the request reaches pending-acceptance.
     */
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

    /**
     * Enable DNS resolution on both sides, retrying while the just-accepted
     * connection is still provisioning — options are only settable once active.
     */
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
     * Flip DNS resolution off ahead of the delete so nothing resolves the
     * peer's private hostnames while the routes are being reclaimed. Options
     * are only settable on an active connection — anything else (still
     * pending, already deleting) has nothing to switch off.
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
