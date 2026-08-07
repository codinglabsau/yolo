<?php

namespace Codinglabs\Yolo\Steps\Sync\App\Tenant;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Concerns\ResolvesCanonicalHost;
use Codinglabs\Yolo\Concerns\ResolvesHttpsListener;
use Codinglabs\Yolo\Resources\ElbV2\TenantRedirectListenerRule;

class SyncRedirectRuleStep extends TenantStep
{
    use ResolvesCanonicalHost;
    use ResolvesHttpsListener;
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

        // Only a tenant served on its apex (or `www.{apex}`) has a sibling to
        // redirect; any other subdomain is served alone.
        if (! $this->hasWwwSibling($this->config['apex'], $this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        $listener = $this->httpsListener();

        if ($listener === null) {
            if ((bool) Arr::get($options, 'dry-run') && $this->certificateWillBeIssued($this->config['certificate-domain'])) {
                $this->recordChange(Change::make('redirect rule', null, 'created'));

                return StepResult::WOULD_SYNC;
            }

            return StepResult::SKIPPED;
        }

        return $this->syncResource(
            new TenantRedirectListenerRule($listener['ListenerArn'], $this->tenantId, $this->config['domain'], $this->config['apex']),
            $options,
        );
    }
}
