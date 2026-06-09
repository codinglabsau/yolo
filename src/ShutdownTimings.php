<?php

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * One source of truth for how each container shuts down, so supervisord's
 * per-program stop waits and ECS's stopTimeout can't drift apart.
 *
 * Each process has one knob — `shutdown-grace-period`: how long it gets to finish
 * work on SIGTERM before being killed. Octane sits behind the ALB, so its grace
 * doubles as the target group's deregistration delay and the entrypoint drain. The
 * queue worker defaults longer (its in-flight job can outlast an ALB drain); the
 * scheduler just needs its current cron tick to finish. Programs are placed by task
 * presence (Manifest::queueHost / schedulerHost), not flags — so the graces for a
 * given container follow from which group it is:
 *
 *     tasks:
 *       web:
 *         shutdown-grace-period: 10     # web (octane) process; also the ALB drain window
 *       queue:
 *         shutdown-grace-period: 90     # standalone queue worker — let a long job finish
 */
final class ShutdownTimings
{
    // Queue jobs routinely outlast an ALB drain, so the worker defaults to a
    // longer window than the web process to finish the in-flight job on shutdown.
    public const int QUEUE_DEFAULT_GRACE = 70;

    // The web process's graceful-stop window when not set in the manifest.
    private const int WEB_DEFAULT_GRACE = 10;

    // A standalone scheduler's graceful-stop window: long enough to let an
    // in-flight schedule:run tick finish. A scheduled command that routinely
    // outlasts this belongs on the queue, not the cron tick.
    private const int SCHEDULER_DEFAULT_GRACE = 10;

    // The bundled SSR renderer's graceful-stop window. A render is sub-second and
    // stateless (Inertia falls back to client-side rendering if it's gone), so it
    // only needs a moment to finish an in-flight render before SIGKILL.
    private const int SSR_DEFAULT_GRACE = 5;

    // Headroom between the longest graceful stop and ECS's SIGKILL so a process
    // draining right up to its window isn't cut off at the wire.
    private const int STOP_TIMEOUT_BUFFER = 5;

    // Fargate hard-caps the container stopTimeout at 120s.
    private const int MAX_STOP_TIMEOUT = 120;

    /**
     * The web (octane) process's graceful-stop window. Octane is behind the ALB,
     * so this is also the target group's deregistration delay and how long the
     * entrypoint keeps serving on SIGTERM before forwarding the stop.
     */
    public static function webGrace(): int
    {
        return (int) Manifest::get('tasks.web.shutdown-grace-period', self::WEB_DEFAULT_GRACE);
    }

    /**
     * Seconds the entrypoint keeps serving after SIGTERM before forwarding the
     * stop — the window the ALB needs to stop routing. Zero when headless: with
     * no target group there's nothing to drain, so forward the stop immediately.
     */
    public static function drain(): int
    {
        return Manifest::isHeadless() ? 0 : self::webGrace();
    }

    /**
     * The standalone queue worker's graceful-stop window. Defaults longer than web
     * because an in-flight job can outlast an ALB drain. Read from `tasks.queue`
     * (only present when extracted); a queue bundled in the web container has no
     * block to override, so it gets the default.
     */
    public static function queueGrace(): int
    {
        return (int) Manifest::get('tasks.queue.shutdown-grace-period', self::QUEUE_DEFAULT_GRACE);
    }

    /**
     * The scheduler's graceful-stop window — just long enough to wait out an
     * in-flight schedule:run tick. Read from `tasks.scheduler` (only present when
     * extracted); a bundled scheduler gets the default.
     */
    public static function schedulerGrace(): int
    {
        return (int) Manifest::get('tasks.scheduler.shutdown-grace-period', self::SCHEDULER_DEFAULT_GRACE);
    }

    /**
     * The bundled SSR renderer's graceful-stop window (the object form of
     * `tasks.web.ssr` can override it). SSR is always bundled in the web container.
     */
    public static function ssrGrace(): int
    {
        $value = Manifest::get('tasks.web.ssr');

        return is_array($value) ? (int) ($value['shutdown-grace-period'] ?? self::SSR_DEFAULT_GRACE) : self::SSR_DEFAULT_GRACE;
    }

    /**
     * The supervisord programs that run in a given container => each one's
     * graceful-stop window. Placement is by task presence, not flags: the web
     * server and (when enabled) ssr are always web; the queue worker and the
     * scheduler ride whichever container hosts them (Manifest::queueHost /
     * schedulerHost). Every app runs all three roles somewhere, so a plain web
     * app's web container runs web + queue + scheduler. The `web` program is
     * Octane by default, or FrankenPHP classic mode when tasks.web.octane is off
     * (ProcessCommands::web) — its grace is the same either way.
     *
     * @return array<string, int>
     */
    public static function programGraces(ServerGroup $group = ServerGroup::WEB): array
    {
        $graces = match ($group) {
            ServerGroup::WEB => [
                'web' => self::webGrace(),
                'ssr' => Manifest::bundles('ssr') ? self::ssrGrace() : null,
                'scheduler' => Manifest::schedulerHost() === ServerGroup::WEB ? self::schedulerGrace() : null,
                'queue' => Manifest::queueHost() === ServerGroup::WEB ? self::queueGrace() : null,
            ],
            ServerGroup::QUEUE => [
                'scheduler' => Manifest::schedulerHost() === ServerGroup::QUEUE ? self::schedulerGrace() : null,
                'queue' => self::queueGrace(),
            ],
            ServerGroup::SCHEDULER => [
                'scheduler' => self::schedulerGrace(),
            ],
        };

        return array_filter($graces, fn (?int $grace): bool => $grace !== null);
    }

    /**
     * ECS's SIGTERM-to-SIGKILL ceiling for a group's task, with buffer and capped
     * at Fargate's 120s. The drain runs the scheduler down first (cron halted,
     * in-flight schedule:run waited out — overlapping the ALB window on web), then
     * supervisord stops the remaining programs in parallel for the slowest of their
     * graces. Without a scheduler it's the drain window plus the slowest program.
     * Only the web container has an ALB drain window; queue/scheduler tasks have no
     * load balancer to keep serving for.
     */
    public static function stopTimeoutFor(ServerGroup $group): int
    {
        $graces = self::programGraces($group);
        $drainWindow = $group === ServerGroup::WEB ? self::drain() : 0;

        if (isset($graces['scheduler'])) {
            $rest = array_diff_key($graces, ['scheduler' => 0]);
            $total = max($drainWindow, $graces['scheduler']) + ($rest === [] ? 0 : max($rest));
        } else {
            $total = $drainWindow + max($graces);
        }

        return min($total + self::STOP_TIMEOUT_BUFFER, self::MAX_STOP_TIMEOUT);
    }
}
