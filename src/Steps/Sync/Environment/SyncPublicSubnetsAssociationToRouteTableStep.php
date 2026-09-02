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
 * AWS exposes no direct lookup for an association, so diff against the route
 * table's `Associations` block and only associate the missing subnets —
 * re-associating all three every sync recorded no Change and kept tripping the
 * confirm gate on a clean account.
 */
class SyncPublicSubnetsAssociationToRouteTableStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

        $associatedSubnetIds = $this->associatedSubnetIds();

        // An unresolved subnet (greenfield plan pass) counts as missing so it
        // reports pending; resolving it here avoids a second lookup on apply.
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
     * The main association carries no SubnetId and is skipped. Empty when the
     * route table isn't provisioned yet (greenfield plan pass).
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
