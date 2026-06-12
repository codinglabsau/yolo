<?php

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * One source of truth for how each container shuts down, so supervisord's
 * per-program stop waits and ECS's stopTimeout can't drift apart.
 *
 * Each process has one knob — `shutdown-grace-period`: how long it gets to finish
 * work on SIGTERM before being killed. Octane sits behind the ALB, so its grace
 * doubles as the target group's deregistration delay and the entrypoint drain. The
 * queue worker defaults longer (its in-flight job can outlast an ALB drain); the
 * scheduler defaults to the whole stop window, since its stop overlaps the other
 * programs' (see stopTimeoutFor). Programs are placed by task presence
 * (Manifest::queueHost / schedulerHost), not flags — so the graces for a
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

    // The bundled SSR renderer's graceful-stop window. A render is sub-second and
    // stateless (Inertia falls back to client-side rendering if it's gone), so it
    // only needs a moment to finish an in-flight render before SIGKILL.
    private const int SSR_DEFAULT_GRACE = 5;

    // Headroom between the longest graceful stop and ECS's SIGKILL so a process
    // draining right up to its window isn't cut off at the wire.
    private const int STOP_TIMEOUT_BUFFER = 5;

    // Fargate hard-caps the container stopTimeout at 120s.
    private const int MAX_STOP_TIMEOUT = 120;

    // The scheduler's graceful-stop window: everything Fargate allows. supercronic
    // is signalled the instant SIGTERM lands (no new schedule:run fires) and its
    // stop overlaps every other program's rather than preceding them (see
    // stopTimeoutFor), so handing the in-flight run the whole stop budget costs
    // the other graces nothing. A run killed at the wire anyway is acceptable by
    // design — scheduled work must self-heal across ticks.
    private const int SCHEDULER_DEFAULT_GRACE = self::MAX_STOP_TIMEOUT - self::STOP_TIMEOUT_BUFFER;

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
     * The scheduler's graceful-stop window — how long an in-flight schedule:run
     * gets to finish after supercronic stops launching new ticks. Defaults to the
     * whole stop budget (Fargate's cap minus the buffer), since the scheduler's
     * stop overlaps the other programs'. Read from `tasks.scheduler` (only
     * present when extracted); a bundled scheduler gets the default.
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
     * ECS's SIGTERM-to-SIGKILL ceiling for a group's task, hard-capped by Fargate
     * at 120s. The scheduler's stop OVERLAPS the other programs' stops rather
     * than preceding them: supercronic is signalled the moment SIGTERM lands (the
     * web entrypoint signals it before holding the ALB drain window; everywhere
     * else the forward is immediate, and supervisord signals all programs at
     * once), so no new schedule:run fires while the drain window and the other
     * graces play out in parallel. The budget is therefore the slower of the two
     * tracks — the drain window plus the slowest non-scheduler program, or the
     * scheduler's grace — never their sum, which is what lets the in-flight
     * schedule:run keep the whole window. Only the web container has an ALB drain
     * window; queue/scheduler tasks have no load balancer to keep serving for.
     *
     * Graces that overcommit the cap are a manifest error, not a silent clamp —
     * clamping would have supervisord promising a program a window ECS cuts off
     * at the wire.
     */
    public static function stopTimeoutFor(ServerGroup $group): int
    {
        $graces = self::programGraces($group);
        $drainWindow = $group === ServerGroup::WEB ? self::drain() : 0;

        $rest = array_diff_key($graces, ['scheduler' => 0]);
        $total = max($drainWindow + ($rest === [] ? 0 : max($rest)), $graces['scheduler'] ?? 0);

        $stopTimeout = $total + self::STOP_TIMEOUT_BUFFER;

        if ($stopTimeout > self::MAX_STOP_TIMEOUT) {
            throw new IntegrityCheckException(sprintf(
                'The %s container\'s shutdown graces need a %ds stop timeout, but Fargate caps it at %ds — '
                . 'lower a shutdown-grace-period, or extract the queue/scheduler into their own services to '
                . 'split the budget.',
                $group->value,
                $stopTimeout,
                self::MAX_STOP_TIMEOUT,
            ));
        }

        return $stopTimeout;
    }
}
