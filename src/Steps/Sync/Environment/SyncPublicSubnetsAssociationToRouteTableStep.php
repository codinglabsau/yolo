<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\PublicSubnets;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ec2\RouteTable;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Associates the public subnets with the public route table. AWS exposes no direct
 * lookup for an association, but the route table's `Associations` block lists them,
 * so we diff against that and only associate the subnets that aren't already
 * attached — instead of re-associating all three on every sync, which recorded no
 * Change and so kept tripping the confirm gate even on a clean account.
 */
class SyncPublicSubnetsAssociationToRouteTableStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

        $associatedSubnetIds = $this->associatedSubnetIds();

        // Subnets not associated yet, keyed by index → [label, resolved id].
        // An unresolved subnet (a greenfield plan pass) counts as missing so it's
        // reported as pending; resolving it here also avoids a second lookup on apply.
        $missing = [];

        foreach (PublicSubnets::cases() as $index => $case) {
            $subnetId = $this->subnetIdOrNull($index);

            if ($subnetId === null || ! in_array($subnetId, $associatedSubnetIds, true)) {
                $missing[$index] = ['label' => $case->value, 'subnetId' => $subnetId];
            }
        }

        if ($missing === []) {
            return StepResult::SYNCED;
        }

        foreach ($missing as $entry) {
            $this->recordChange(Change::make("route table association ({$entry['label']})", null, 'associated'));
        }

        if ($dryRun) {
            return StepResult::WOULD_SYNC;
        }

        $routeTableId = (new RouteTable())->arn();

        foreach ($missing as $index => $entry) {
            Aws::ec2()->associateRouteTable([
                'RouteTableId' => $routeTableId,
                'SubnetId' => $entry['subnetId'] ?? (new PublicSubnet($index))->arn(),
            ]);
        }

        return StepResult::SYNCED;
    }

    /**
     * The subnet ids already associated with the public route table (the main
     * association carries no SubnetId and is skipped). Empty when the route table
     * isn't provisioned yet (a greenfield plan pass).
     *
     * @return array<int, string>
     */
    protected function associatedSubnetIds(): array
    {
        try {
            $routeTable = Ec2::routeTable((new RouteTable())->name());
        } catch (ResourceDoesNotExistException) {
            return [];
        }

        return collect($routeTable['Associations'] ?? [])
            ->pluck('SubnetId')
            ->filter()
            ->values()
            ->all();
    }

    protected function subnetIdOrNull(int $index): ?string
    {
        try {
            return (new PublicSubnet($index))->arn();
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
