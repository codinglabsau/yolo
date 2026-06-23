<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Route53\HostedZone;

/**
 * Withdraws this app's DNS records — and ONLY its records. The hosted zone itself
 * is never deleted: it's domain-level infrastructure (the registrar's NS delegation
 * points at it, and the domain's email / verification DNS and any sibling
 * environment's records all live in it), so it outlives any single app. Tearing the
 * app down removes the A/AAAA records YOLO inserted for it and leaves the zone — and
 * everything else in it — standing. Mirrors how `destroy:app` treats every other
 * shared resource: withdraw this app's slice, never delete the shared thing. See
 * {@see HostedZone::removeAppRecords()}.
 */
class TeardownHostedZoneStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $zone = new HostedZone(Manifest::apex());

        if (! $zone->exists() || ! $zone->appRecordsExist()) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make(sprintf('%s app DNS records', Manifest::apex()), 'present', null));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $zone->removeAppRecords();

        return StepResult::DELETED;
    }
}
