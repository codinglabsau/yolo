<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

/**
 * Identical to bare `status`; exists so `status:app` / `status:environment` read
 * as a pair like `sync:*` and `audit:*`.
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
