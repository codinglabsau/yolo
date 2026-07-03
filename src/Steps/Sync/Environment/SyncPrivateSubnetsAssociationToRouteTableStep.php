<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\PrivateSubnets;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ec2\PrivateSubnet;
use Codinglabs\Yolo\Resources\Ec2\PrivateRouteTable;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Associates the private subnets with the private route table — without this
 * they'd fall back to the VPC's main route table, whose routing YOLO doesn't
 * control. Mirrors the public association step: diff against the route table's
 * `Associations` block and only associate the subnets that aren't already
 * attached, so a clean environment reports no change. Adopted `private-subnets`
 * keep their owner's routing and are never re-associated.
 */
class SyncPrivateSubnetsAssociationToRouteTableStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::has('private-subnets')) {
            return StepResult::CUSTOM_MANAGED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        $associatedSubnetIds = $this->associatedSubnetIds();

        // Subnets not associated yet, keyed by index → [label, resolved id].
        // An unresolved subnet (a greenfield plan pass) counts as missing so it's
        // reported as pending; resolving it here also avoids a second lookup on apply.
        $missing = [];

        foreach (PrivateSubnets::cases() as $index => $case) {
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

        $routeTableId = (new PrivateRouteTable())->arn();

        foreach ($missing as $index => $entry) {
            Aws::ec2()->associateRouteTable([
                'RouteTableId' => $routeTableId,
                'SubnetId' => $entry['subnetId'] ?? (new PrivateSubnet($index))->arn(),
            ]);
        }

        return StepResult::SYNCED;
    }

    /**
     * The subnet ids already associated with the private route table (the main
     * association carries no SubnetId and is skipped). Empty when the route table
     * isn't provisioned yet (a greenfield plan pass).
     *
     * @return array<int, string>
     */
    protected function associatedSubnetIds(): array
    {
        try {
            $routeTable = Ec2::routeTable((new PrivateRouteTable())->name());
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
            return (new PrivateSubnet($index))->arn();
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
