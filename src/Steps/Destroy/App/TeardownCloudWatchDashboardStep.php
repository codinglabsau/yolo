<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;

/**
 * Tears down this app's CloudWatch dashboard. The dashboard is Deletable but not
 * a full Resource (it carries no tags / ARN), so this drives its delete directly
 * rather than through the generic teardownResource() path.
 */
class TeardownCloudWatchDashboardStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dashboard = new Dashboard();

        if (! $dashboard->exists()) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make($dashboard->name(), 'provisioned', null));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $dashboard->delete();

        return StepResult::DELETED;
    }
}
