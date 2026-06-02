<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Audit\Audit;

/**
 * Audit the env-shared (environment-tier) resources for one environment —
 * VPC, ALB, subnets, RDS SG, SNS topic and the like. Env-scope resources
 * never carry `yolo:app` by design, so `--drift` is a no-op here: drift is
 * an app-scope concept.
 */
class AuditEnvironmentCommand extends AbstractAuditCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('audit:environment')
            ->setDescription('Audit the env-shared (environment-tier) resources for the given environment');
    }

    protected function includes(array $resource): bool
    {
        return $resource['scope'] === Audit::SCOPE_ENV;
    }

    protected function emptyFilterMessage(string $environment): string
    {
        if ($this->option('drift')) {
            return sprintf("No drift at the environment tier in '%s' — drift only applies to app-scope resources.", $environment);
        }

        return sprintf("No environment-tier resources tagged for '%s'.", $environment);
    }
}
