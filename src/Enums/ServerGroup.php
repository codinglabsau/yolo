<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * The value doubles as the resource-name suffix, the container name, the
 * entrypoint role argument and the `tasks.{group}` manifest prefix, so the group
 * is named once and everything downstream follows.
 */
enum ServerGroup: string
{
    case WEB = 'web';
    case QUEUE = 'queue';
    case SCHEDULER = 'scheduler';

    public function manifestPrefix(): string
    {
        return "tasks.{$this->value}";
    }

    public function attachesToLoadBalancer(): bool
    {
        return $this === self::WEB;
    }

    /**
     * Exactly one task, never a scalable target, deployed stop-then-start so a
     * rollout never briefly runs two crons.
     */
    public function isSingleton(): bool
    {
        return $this === self::SCHEDULER;
    }

    public function defaultCpu(): string
    {
        return $this === self::WEB ? '512' : '256';
    }

    /**
     * Paired with defaultCpu() to a valid Fargate CPU/memory combination.
     */
    public function defaultMemory(): string
    {
        return $this === self::WEB ? '1024' : '512';
    }
}
