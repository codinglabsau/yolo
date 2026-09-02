<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * Marks a step that blocks inside a slow AWS waiter; the runner shows its
 * patience message and ticks an elapsed-time heartbeat on every waiter poll so
 * the progress bar doesn't freeze and read as hung.
 */
interface LongRunning extends Step
{
    public function patienceMessage(): string;
}
