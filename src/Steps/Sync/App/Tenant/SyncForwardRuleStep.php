<?php

namespace Codinglabs\Yolo\Steps\Sync\App\Tenant;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Concerns\ResolvesHttpsListener;
use Codinglabs\Yolo\Resources\ElbV2\TenantForwardListenerRule;

class SyncForwardRuleStep extends TenantStep
{
    use ResolvesHttpsListener;
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! isset($this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        // A subdomain under `wildcard-subdomains` is already served by the app's own certificate and rule.
        if (Manifest::servesDomain($this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        $listener = $this->httpsListener();

        if ($listener === null) {
            // The `:443` listener is bootstrapped earlier in this same apply, so it's
            // absent on the plan pass. Report the rule pending when a certificate
            // will be issued this run — a bare SKIPPED is pruned, leaving the
            // tenant's host routed nowhere; otherwise genuinely defer.
            if ((bool) Arr::get($options, 'dry-run') && $this->certificateWillBeIssued($this->config['certificate-domain'])) {
                $this->recordChange(Change::make('forward rule', null, 'created'));

                return StepResult::WOULD_SYNC;
            }

            return StepResult::SKIPPED;
        }

        return $this->syncResource(
            new TenantForwardListenerRule($listener['ListenerArn'], $this->tenantId, $this->config['domain'], $this->config['wildcard-host']),
            $options,
        );
    }
}
