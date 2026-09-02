<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Audit\Audit;

/**
 * Env-scope resources never carry `yolo:app` but can still be `unexpected`, so
 * `--unexpected` is meaningful here.
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
