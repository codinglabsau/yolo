<?php

namespace Codinglabs\Yolo\Commands;

use Symfony\Component\Console\Input\InputArgument;

/**
 * Filters on the `yolo:app` tag, so a resource with no ownership marker never
 * shows up here.
 */
class AuditAppCommand extends AbstractAuditCommand
{
    #[\Override]
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
