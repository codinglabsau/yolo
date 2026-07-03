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
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ec2\RouteTable;
use Codinglabs\Yolo\Resources\Ec2\VpcPeeringConnection;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Reconciles the routes each declared peering rides on, both directions: the
 * peer VPC's CIDR into the environment's public route table (where the Fargate
 * tasks live), and the environment's CIDR into the peer VPC's main route table
 * (where an unmanaged VPC's subnets fall back to). Also prunes blackhole
 * peering routes from the public route table — the debris a torn-down
 * connection leaves behind; the peer side's return route is inert and left to
 * its owner. Diffs against the live `Routes` blocks so a clean environment
 * reports no change.
 */
class SyncVpcPeeringRoutesStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

        // Route entries to write, resolved as far as live state allows —
        // [routeTableId|null, destination CIDR, peer vpc id, direction label].
        // Unresolvable parts (a greenfield plan pass, a peer VPC that doesn't
        // exist yet) are reported as pending, never thrown on.
        $missing = [];

        $environmentCidrBlock = $this->environmentCidrBlock();

        foreach (EnvManifest::peering() as $peerVpcId) {
            $peerVpc = Ec2::vpcById($peerVpcId);

            if ($peerVpc === null) {
                $this->recordChange(Change::make("peering routes ({$peerVpcId})", null, 'peer VPC not found — declared in `peering` but not visible in this account/region'));

                continue;
            }

            $publicRouteTable = $this->routeTableOrNull(fn (): array => Ec2::routeTable((new RouteTable())->name()));

            if (! $this->hasPeeringRoute($publicRouteTable, $peerVpc['CidrBlock'])) {
                $missing[] = [$publicRouteTable['RouteTableId'] ?? null, $peerVpc['CidrBlock'], $peerVpcId, 'outbound'];
            }

            // Every VPC has a main route table, so a peer that resolved above
            // always yields one — the null guard just ensures a failed lookup
            // can never fall back onto the environment's own table at apply.
            $peerMainRouteTable = Ec2::mainRouteTable($peerVpcId);

            if ($environmentCidrBlock !== null && $peerMainRouteTable !== null && ! $this->hasPeeringRoute($peerMainRouteTable, $environmentCidrBlock)) {
                $missing[] = [$peerMainRouteTable['RouteTableId'], $environmentCidrBlock, $peerVpcId, 'return'];
            }
        }

        $blackholes = $this->blackholePeeringRoutes();

        if ($missing === [] && $blackholes === []) {
            // A declared peer VPC that doesn't exist recorded a change above —
            // that's genuine drift (fix or remove the manifest entry), so the
            // plan must say so even though there's nothing to write.
            return $this->changes() === [] || ! $dryRun ? StepResult::SYNCED : StepResult::WOULD_SYNC;
        }

        foreach ($missing as [$routeTableId, $destination, $peerVpcId, $direction]) {
            $this->recordChange(Change::make("{$direction} route {$destination} ({$peerVpcId})", null, 'peering connection'));
        }

        foreach ($blackholes as $route) {
            $this->recordChange(Change::make("blackhole route {$route['DestinationCidrBlock']}", $route['VpcPeeringConnectionId'], null));
        }

        if ($dryRun) {
            return StepResult::WOULD_SYNC;
        }

        foreach ($missing as [$routeTableId, $destination, $peerVpcId, $direction]) {
            Aws::ec2()->createRoute([
                'DestinationCidrBlock' => $destination,
                'VpcPeeringConnectionId' => (new VpcPeeringConnection($peerVpcId))->arn(),
                'RouteTableId' => $routeTableId ?? (new RouteTable())->arn(),
            ]);
        }

        foreach ($blackholes as $route) {
            Aws::ec2()->deleteRoute([
                'RouteTableId' => (new RouteTable())->arn(),
                'DestinationCidrBlock' => $route['DestinationCidrBlock'],
            ]);
        }

        return StepResult::SYNCED;
    }

    /**
     * Whether a route table already carries an active peering route for the
     * destination. A null table (not provisioned yet — greenfield plan pass)
     * has no routes, so the missing route is reported as pending.
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
     * Peering routes in the public route table whose connection is gone — a
     * torn-down peering leaves its routes in blackhole state; sync reclaims
     * them so the table stays exactly what the manifest declares.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function blackholePeeringRoutes(): array
    {
        $routeTable = $this->routeTableOrNull(fn (): array => Ec2::routeTable((new RouteTable())->name()));

        return collect($routeTable['Routes'] ?? [])
            ->filter(fn (array $route): bool => str_starts_with($route['VpcPeeringConnectionId'] ?? '', 'pcx-')
                && ($route['State'] ?? '') === 'blackhole'
                && isset($route['DestinationCidrBlock']))
            ->values()
            ->all();
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
     * @param  callable(): array<string, mixed>  $lookup
     * @return array<string, mixed>|null
     */
    protected function routeTableOrNull(callable $lookup): ?array
    {
        try {
            return $lookup();
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
