<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * The three workloads an app runs. Each can live in its own ECS service +
 * task-definition (`web` always; `queue` / `scheduler` when extracted via a
 * top-level `tasks.queue` / `tasks.scheduler` block). A queue/scheduler that isn't
 * extracted is bundled into another container, derived from task presence: the
 * queue worker rides web, and the scheduler rides the standalone queue if there is
 * one, else web (see Manifest::queueHost / schedulerHost).
 *
 * The enum value doubles as the resource-name suffix (`yolo-{env}-{app}-web`),
 * the task-definition container name, the entrypoint role argument and the
 * `tasks.{group}` manifest prefix, so the group is named once and everything
 * downstream follows.
 */
enum ServerGroup: string
{
    case WEB = 'web';
    case QUEUE = 'queue';
    case SCHEDULER = 'scheduler';

    /**
     * The manifest block this group reads its standalone-service config from
     * (`tasks.web` / `tasks.queue` / `tasks.scheduler`).
     */
    public function manifestPrefix(): string
    {
        return "tasks.{$this->value}";
    }

    /**
     * Only the web service sits behind the ALB (target group, health-check grace,
     * port mapping). The queue and scheduler are headless workers.
     */
    public function attachesToLoadBalancer(): bool
    {
        return $this === self::WEB;
    }

    /**
     * The scheduler is a pinned singleton — exactly one task, never a scalable
     * target, deployed stop-then-start so a rollout never briefly runs two crons.
     */
    public function isSingleton(): bool
    {
        return $this === self::SCHEDULER;
    }

    /**
     * Default Fargate task CPU units. The web tier serves requests so it gets the
     * larger default; the queue and scheduler are lighter and start at 0.25 vCPU.
     */
    public function defaultCpu(): string
    {
        return $this === self::WEB ? '512' : '256';
    }

    /**
     * Default Fargate task memory (MiB) — paired with defaultCpu() to a valid
     * Fargate CPU/memory combination (256 → 512, 512 → 1024).
     */
    public function defaultMemory(): string
    {
        return $this === self::WEB ? '1024' : '512';
    }
}
