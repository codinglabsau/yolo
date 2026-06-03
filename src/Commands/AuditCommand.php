<?php

namespace Codinglabs\Yolo\Commands;

/**
 * Top-level audit verb — shows every YOLO-tagged resource in an environment,
 * grouped by ownership scope (account → env → app). Mirrors how bare `sync`
 * orchestrates all three tiers; use `audit:environment` / `audit:app` to
 * narrow to one tier.
 */
class AuditCommand extends AbstractAuditCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('audit')
            ->setDescription('Audit YOLO-tagged resources for an environment (account → environment → app) and flag drift, orphans and unexplained resources');
    }

    protected function includes(array $resource): bool
    {
        return true;
    }

    protected function emptyFilterMessage(string $environment): string
    {
        if ($this->option('drift')) {
            return sprintf("No drift in '%s' — every tagged resource maps to a live app.", $environment);
        }

        return sprintf("Nothing tagged for '%s'.", $environment);
    }
}
