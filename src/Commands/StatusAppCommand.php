<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

/**
 * The app-tier status under the scope-first namespace — identical to bare
 * `status` (the app scope is the default), provided so `status:app` and
 * `status:environment` read as a pair, the way `sync:*` and `audit:*` do.
 */
class StatusAppCommand extends StatusCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('status:app')
            ->setDescription("Show a snapshot of one app's services, load, scaling and any in-progress deploy");
    }
}
