<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App\Solo;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\Route53\HostedZone;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Syncs the app's own zone, plus one per additional landlord host
 * ({@see Manifest::additionalDomains()}) whose apex isn't already covered — a
 * landlord serving several brand domains needs a zone per apex, not just the
 * primary's. Most apps declare a single host, so this is the one-zone case in
 * the common shape and a small fan-out only when `domain` is a list.
 */
class SyncHostedZoneStep implements ExecutesWebStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $created = false;
        $synced = false;

        foreach (Manifest::appApexes() as $apex) {
            match ($this->syncResource(new HostedZone($apex), $options)) {
                StepResult::CREATED, StepResult::WOULD_CREATE => $created = true,
                StepResult::SYNCED, StepResult::WOULD_SYNC => $synced = true,
                default => null,
            };
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        return match (true) {
            $created => $dryRun ? StepResult::WOULD_CREATE : StepResult::CREATED,
            $synced => $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED,
            default => StepResult::SYNCED,
        };
    }
}
