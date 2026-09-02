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
 * AWS exposes no direct lookup for a single route, so diff against the route
 * table's `Routes` block and only createRoute when absent — re-stamping it every
 * sync recorded no Change and kept tripping the confirm gate on a clean account.
 */
class SyncDefaultRouteStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

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

    /** False when the route table isn't provisioned yet (greenfield plan pass), so the route reports pending. */
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
