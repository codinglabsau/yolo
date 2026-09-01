<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App\Tenant;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ElbV2\TenantForwardListenerRule;

/**
 * Removes one tenant's forward rule from the shared `:443` listener, found by its
 * tenant-keyed Name tag so no sibling tenant's rule (or another app's) is touched.
 * Runs before the target group is deleted — a rule whose action references a
 * target group blocks that group's delete.
 *
 * A tenant the app's own certificate already covers never had a rule of its own,
 * so it skips here exactly as it skipped in sync.
 */
class TeardownForwardRuleStep extends TenantStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! isset($this->config['domain']) || Manifest::servesDomain($this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        try {
            $listener = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);
        } catch (ResourceDoesNotExistException) {
            return StepResult::SKIPPED;
        }

        return $this->teardownResource(
            new TenantForwardListenerRule($listener['ListenerArn'], $this->tenantId, $this->config['domain'], $this->config['wildcard-host']),
            $options,
        );
    }
}
