<?php

namespace Codinglabs\Yolo\Steps\Sync\App\Tenant;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Resources\Route53\HostedZone;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncHostedZoneStep extends TenantStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! isset($this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        // A tenant already served by the app's own certificate and rule (a subdomain
        // under `wildcard-subdomains`) needs nothing of its own here.
        if (Manifest::servesDomain($this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new HostedZone($this->config['apex']), $options);
    }
}
