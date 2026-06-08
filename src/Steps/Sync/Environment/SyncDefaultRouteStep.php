<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ec2\RouteTable;
use Codinglabs\Yolo\Resources\Ec2\InternetGateway;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The 0.0.0.0/0 → internet gateway default route on the public route table.
 * AWS exposes no direct lookup for a single route, but the route table's `Routes`
 * block lists them, so we diff against that and only call createRoute when the
 * default route is absent — instead of re-stamping it (idempotently) on every
 * sync, which recorded no Change and so kept tripping the confirm gate even on a
 * clean account (LPX-646).
 */
class SyncDefaultRouteStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

        // Already routed → nothing to do, so the step is pruned before apply and a
        // clean environment reports "Already in sync".
        if ($this->hasDefaultRoute()) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make('default route 0.0.0.0/0', null, 'internet gateway'));

        if ($dryRun) {
            return StepResult::WOULD_SYNC;
        }

        Aws::ec2()->createRoute([
            'DestinationCidrBlock' => '0.0.0.0/0',
            'GatewayId' => (new InternetGateway())->arn(),
            'RouteTableId' => (new RouteTable())->arn(),
        ]);

        return StepResult::SYNCED;
    }

    /**
     * Whether the public route table already carries a 0.0.0.0/0 route to an
     * internet gateway. False when the route table isn't provisioned yet (a
     * greenfield plan pass), so the missing route is reported as pending.
     */
    protected function hasDefaultRoute(): bool
    {
        try {
            $routeTable = Ec2::routeTable((new RouteTable())->name());
        } catch (ResourceDoesNotExistException) {
            return false;
        }

        foreach ($routeTable['Routes'] ?? [] as $route) {
            if (($route['DestinationCidrBlock'] ?? null) === '0.0.0.0/0'
                && str_starts_with($route['GatewayId'] ?? '', 'igw-')) {
                return true;
            }
        }

        return false;
    }
}
