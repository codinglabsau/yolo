<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Audit\Audit;

/**
 * Audit the env-shared (environment-tier) resources for one environment —
 * VPC, ALB, subnets, RDS SG, SNS topic and the like. Env-scope resources
 * never carry `yolo:app`, but they can still be `unexpected` — an untagged
 * resource sitting in the env namespace, or a leftover of a service YOLO no
 * longer provisions — so `--unexpected` is meaningful here.
 */
class AuditEnvironmentCommand extends AbstractAuditCommand
{
    #[\Override]
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
        if ($this->option('unexpected')) {
            return sprintf("Nothing unexpected at the environment tier in '%s'.", $environment);
        }

        return sprintf("No environment-tier resources tagged for '%s'.", $environment);
    }
}
