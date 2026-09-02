<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App\Tenant;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Concerns\ResolvesCanonicalHost;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ElbV2\TenantRedirectListenerRule;
use Codinglabs\Yolo\Steps\Sync\App\Tenant\SyncRedirectRuleStep;

/**
 * Twin of {@see SyncRedirectRuleStep}, gated on the same conditions.
 */
class TeardownRedirectRuleStep extends TenantStep
{
    use ResolvesCanonicalHost;
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! isset($this->config['domain']) || Manifest::servesDomain($this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        if (! $this->hasWwwSibling($this->config['apex'], $this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        try {
            $listener = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);
        } catch (ResourceDoesNotExistException) {
            return StepResult::SKIPPED;
        }

        return $this->teardownResource(
            new TenantRedirectListenerRule($listener['ListenerArn'], $this->tenantId, $this->config['domain'], $this->config['apex']),
            $options,
        );
    }
}
