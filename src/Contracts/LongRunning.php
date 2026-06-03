<?php

namespace Codinglabs\Yolo\Contracts;

/**
 * Marks a step whose work is a slow AWS provision — a fresh ElastiCache
 * replication group takes 5–15 minutes, a deploy task runs migrations. Such
 * steps block inside an AWS waiter, which would otherwise freeze the progress
 * bar at its last frame and read as hung.
 *
 * The stepped-command runner gives a LongRunning step special treatment: it
 * shows the patience message up front and ticks an elapsed-time heartbeat on
 * every waiter poll (via WaitReporter), so the UI keeps moving and the user
 * sees "this is supposed to take a while, and it is progressing".
 */
interface LongRunning extends Step
{
    /**
     * A one-line reassurance shown while the step runs, e.g.
     * "Provisioning the Valkey cache cluster — usually 5–15 minutes.".
     */
    public function patienceMessage(): string;
}
