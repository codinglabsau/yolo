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
 * Withdraws this app's DNS records — and ONLY its records — across every zone it
 * has one in: the primary domain's apex, plus each additional landlord host's own
 * apex when it differs (a landlord serving several brand domains). The hosted
 * zones themselves are never deleted: they're domain-level infrastructure (the
 * registrar's NS delegation points at them, and the domain's email / verification
 * DNS and any sibling environment's records all live there), so they outlive any
 * single app. Tearing the app down removes the A/AAAA records YOLO inserted and
 * leaves every zone — and everything else in it — standing. Mirrors how
 * `destroy:app` treats every other shared resource: withdraw this app's slice,
 * never delete the shared thing. See {@see HostedZone::removeAppRecords()}.
 */
class WithdrawAppDnsRecordsStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        // No domain of its own means no app-level records to withdraw — a tenanted
        // app's per-tenant records are withdrawn by the per-tenant teardown.
        if (! Manifest::hasDomain()) {
            return StepResult::SKIPPED;
        }

        $withdrawn = false;

        foreach (Manifest::appApexes() as $apex) {
            $zone = new HostedZone($apex);

            if (! $zone->exists()) {
                continue;
            }

            $records = $zone->appRecords();

            if ($records === []) {
                continue;
            }

            // Name each record withdrawn — type + host (e.g. `A www.example.com`).
            // Only this app's own A/AAAA alias records go; the shared, domain-level
            // hosted zone and everything else in it (MX, NS, sibling envs) stays.
            // The step is a withdrawal, never a zone delete — see {@see HostedZone}.
            foreach ($records as $record) {
                $this->recordChange(Change::make(sprintf('%s %s', $record['Type'], $record['Name']), 'present', null));
            }

            $withdrawn = true;

            if (! Arr::get($options, 'dry-run')) {
                $zone->removeAppRecords();
            }
        }

        if (! $withdrawn) {
            return StepResult::SKIPPED;
        }

        return Arr::get($options, 'dry-run') ? StepResult::WOULD_DELETE : StepResult::DELETED;
    }
}
