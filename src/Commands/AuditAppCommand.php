<?php

namespace Codinglabs\Yolo\Commands;

use Symfony\Component\Console\Input\InputArgument;

/**
 * Audit one app's resources in an environment. Filters the env-wide audit
 * report to rows whose `yolo:app` tag matches the given app — so a resource
 * with no ownership marker never shows up here, only `ok` and `unexpected`
 * rows (a dead app's leftovers, or a service YOLO no longer provisions) for
 * that app.
 */
class AuditAppCommand extends AbstractAuditCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('audit:app')
            ->addArgument('app', InputArgument::REQUIRED, 'The app name (matches the resource\'s yolo:app tag)')
            ->setDescription("Audit a single app's resources for the given environment");
    }

    protected function includes(array $resource): bool
    {
        return $resource['app'] === $this->argument('app');
    }

    protected function emptyFilterMessage(string $environment): string
    {
        $app = $this->argument('app');

        if ($this->option('unexpected')) {
            return sprintf("Nothing unexpected for app '%s' in '%s'.", $app, $environment);
        }

        return sprintf("No resources tagged for app '%s' in '%s'.", $app, $environment);
    }
}
