<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Concerns\RecordsWarnings;
use Codinglabs\Yolo\Resources\Route53\HostedZone;

/**
 * Tears down this app's Route 53 records — and the hosted zone too, but only when
 * it's safe. Hosted zones are shared across environments and a real domain often
 * carries operator-added records (email MX/SPF/DKIM, verification CNAMEs), so this
 * step is deliberately conservative:
 *
 *  - It FAILS CLOSED on ownership. The zone is touched only when its
 *    `yolo:environment` tag positively names this environment. A zone owned by a
 *    sibling env, a zone YOLO doesn't own (untagged), or an inconclusive read
 *    (Route 53 error) all skip with a warning — never an irreversible delete of a
 *    shared or foreign zone on a guess.
 *  - It removes only YOLO-managed records and deletes the zone only if it's then
 *    empty of everything but the apex NS/SOA (see {@see HostedZone::teardown()}),
 *    so a domain that also serves email keeps its zone (and a warning says so).
 */
class TeardownHostedZoneStep implements Step
{
    use RecordsChanges;
    use RecordsWarnings;

    public function __invoke(array $options): StepResult
    {
        $apex = Manifest::apex();
        $zone = new HostedZone($apex);

        if (! $zone->exists()) {
            return StepResult::SKIPPED;
        }

        try {
            $owner = $zone->ownerTag();
        } catch (\Throwable) {
            $this->recordWarning(sprintf('Could not read hosted-zone ownership for %s — left it in place. Re-run once Route 53 is reachable.', $apex));

            return StepResult::SKIPPED;
        }

        if ($owner !== Helpers::app('environment')) {
            $this->recordWarning(sprintf(
                'Hosted zone %s is %s — left in place; destroy:app only removes a zone this environment owns.',
                $apex,
                $owner === null ? 'not YOLO-owned' : sprintf('owned by the "%s" environment', $owner),
            ));

            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make('hosted zone records', 'provisioned', null));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        if (! $zone->teardown()) {
            $this->recordWarning(sprintf(
                'Hosted zone %s kept — it still holds non-YOLO records (e.g. email or domain-verification DNS). Its records for this app were removed; delete the zone manually if you are decommissioning the domain.',
                $apex,
            ));
        }

        return StepResult::DELETED;
    }
}
