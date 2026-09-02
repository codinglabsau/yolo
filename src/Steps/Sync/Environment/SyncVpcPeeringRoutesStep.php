<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ec2\RouteTable;
use Codinglabs\Yolo\Resources\Ec2\PrivateRouteTable;
use Codinglabs\Yolo\Resources\Ec2\VpcPeeringConnection;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Outbound, the peer CIDR goes into EVERY yolo-managed route table so both the
 * task and database tiers can dial across. Return, the env CIDR goes into every
 * peer-VPC table that governs a subnet, falling back to the peer's main table
 * only when nothing is associated — a route in an unassociated main table
 * steers no subnet, and every reply black-holes. Also prunes blackhole peering
 * routes from the yolo-managed tables (debris of an interrupted teardown).
 */
class SyncVpcPeeringRoutesStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

        $environmentCidrBlock = $this->environmentCidrBlock();

        // Each may be null on a greenfield plan pass, so a missing route is
        // reported pending and the table id resolves at apply time.
        $environmentRouteTables = [
            [new RouteTable(), $this->liveRouteTableOrNull(new RouteTable())],
            [new PrivateRouteTable(), $this->liveRouteTableOrNull(new PrivateRouteTable())],
        ];

        // [routeTableId|null, env table resource for late resolution|null,
        // destination CIDR, peer vpc id, plan attribute]. Unresolvable parts
        // (greenfield plan pass, peer VPC not yet visible) report pending, never throw.
        $missingRoutes = [];

        foreach (EnvManifest::peering() as $peerVpcId) {
            $peerVpc = Ec2::vpcById($peerVpcId);

            if ($peerVpc === null) {
                $this->recordChange(Change::make("peering routes ({$peerVpcId})", null, 'peer VPC not found — declared in `peering` but not visible in this account/region'));

                continue;
            }

            foreach ($environmentRouteTables as [$routeTableResource, $liveRouteTable]) {
                if (! $this->hasPeeringRoute($liveRouteTable, $peerVpc['CidrBlock'])) {
                    $missingRoutes[] = [
                        $liveRouteTable['RouteTableId'] ?? null,
                        $routeTableResource,
                        $peerVpc['CidrBlock'],
                        $peerVpcId,
                        sprintf('outbound route %s (%s)', $peerVpc['CidrBlock'], $liveRouteTable['RouteTableId'] ?? $routeTableResource->name()),
                    ];
                }
            }

            if ($environmentCidrBlock === null) {
                continue;
            }

            foreach (Ec2::subnetAssociatedRouteTables($peerVpcId) as $peerRouteTable) {
                if (! $this->hasPeeringRoute($peerRouteTable, $environmentCidrBlock)) {
                    $missingRoutes[] = [
                        $peerRouteTable['RouteTableId'],
                        null,
                        $environmentCidrBlock,
                        $peerVpcId,
                        sprintf('return route %s (peer %s — not yolo-managed)', $environmentCidrBlock, $peerRouteTable['RouteTableId']),
                    ];
                }
            }
        }

        $blackholeRoutes = $this->blackholePeeringRoutes($environmentRouteTables);

        if ($missingRoutes === [] && $blackholeRoutes === []) {
            // A declared peer VPC that doesn't exist recorded a change above —
            // genuine drift, so the plan must say so even with nothing to write.
            return $this->changes() === [] || ! $dryRun ? StepResult::SYNCED : StepResult::WOULD_SYNC;
        }

        foreach ($missingRoutes as [$routeTableId, $routeTableResource, $destination, $peerVpcId, $attribute]) {
            $this->recordChange(Change::make($attribute, null, 'peering connection'));
        }

        foreach ($blackholeRoutes as [$routeTableId, $route]) {
            $this->recordChange(Change::make("blackhole route {$route['DestinationCidrBlock']} ({$routeTableId})", $route['VpcPeeringConnectionId'], null));
        }

        if ($dryRun) {
            return StepResult::WOULD_SYNC;
        }

        foreach ($missingRoutes as [$routeTableId, $routeTableResource, $destination, $peerVpcId, $attribute]) {
            Aws::ec2()->createRoute([
                'DestinationCidrBlock' => $destination,
                'VpcPeeringConnectionId' => (new VpcPeeringConnection($peerVpcId))->arn(),
                'RouteTableId' => $routeTableId ?? $routeTableResource->arn(),
            ]);
        }

        foreach ($blackholeRoutes as [$routeTableId, $route]) {
            Aws::ec2()->deleteRoute([
                'RouteTableId' => $routeTableId,
                'DestinationCidrBlock' => $route['DestinationCidrBlock'],
            ]);
        }

        return StepResult::SYNCED;
    }

    /**
     * A null table (greenfield plan pass) has no routes, so the route reports pending.
     *
     * @param  array<string, mixed>|null  $routeTable
     */
    protected function hasPeeringRoute(?array $routeTable, string $destination): bool
    {
        return collect($routeTable['Routes'] ?? [])->contains(
            fn (array $route): bool => ($route['DestinationCidrBlock'] ?? null) === $destination
                && str_starts_with($route['VpcPeeringConnectionId'] ?? '', 'pcx-')
                && ($route['State'] ?? 'active') === 'active'
        );
    }

    /**
     * An interrupted teardown leaves its routes in blackhole state.
     *
     * @param  array<int, array{0: RouteTable|PrivateRouteTable, 1: array<string, mixed>|null}>  $environmentRouteTables
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    protected function blackholePeeringRoutes(array $environmentRouteTables): array
    {
        $blackholeRoutes = [];

        foreach ($environmentRouteTables as [$routeTableResource, $liveRouteTable]) {
            foreach ($liveRouteTable['Routes'] ?? [] as $route) {
                if (str_starts_with($route['VpcPeeringConnectionId'] ?? '', 'pcx-')
                    && ($route['State'] ?? '') === 'blackhole'
                    && isset($route['DestinationCidrBlock'])) {
                    $blackholeRoutes[] = [$liveRouteTable['RouteTableId'], $route];
                }
            }
        }

        return $blackholeRoutes;
    }

    protected function environmentCidrBlock(): ?string
    {
        try {
            return Ec2::vpc((new Vpc())->name())['CidrBlock'];
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function liveRouteTableOrNull(Resource $routeTable): ?array
    {
        try {
            return Ec2::routeTable($routeTable->name());
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
