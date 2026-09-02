<?php

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * One source of truth for container shutdown, so supervisord's per-program stop waits and
 * ECS's stopTimeout can't drift apart. Each process has one knob, `shutdown-grace-period`.
 * Octane sits behind the ALB, so its grace doubles as the deregistration delay and the
 * entrypoint drain; programs are placed by task presence (Manifest::queueHost /
 * schedulerHost), so a container's graces follow from which group it is.
 */
final class ShutdownTimings
{
    // An in-flight job routinely outlasts an ALB drain.
    public const int QUEUE_DEFAULT_GRACE = 60;

    private const int WEB_DEFAULT_GRACE = 15;

    // A render is sub-second and stateless (Inertia falls back to CSR).
    private const int SSR_DEFAULT_GRACE = 5;

    // So a process draining right up to its window isn't cut off at the wire.
    private const int STOP_TIMEOUT_BUFFER = 5;

    // Fargate's hard cap.
    private const int MAX_STOP_TIMEOUT = 120;

    // The scheduler's stop overlaps every other program's (see stopTimeoutFor), so giving the
    // in-flight run the whole budget costs the other graces nothing; a run killed at the wire
    // is acceptable — scheduled work must self-heal across ticks.
    private const int SCHEDULER_DEFAULT_GRACE = self::MAX_STOP_TIMEOUT - self::STOP_TIMEOUT_BUFFER;

    /** Also the target group's deregistration delay and the entrypoint drain. */
    public static function webGrace(): int
    {
        return (int) Manifest::get('tasks.web.shutdown-grace-period', self::WEB_DEFAULT_GRACE);
    }

    /** Every web app sits behind the ALB, so the drain is always the web grace. */
    public static function drain(): int
    {
        return self::webGrace();
    }

    /** A bundled queue has no `tasks.queue` block to override, so it gets the default. */
    public static function queueGrace(): int
    {
        return (int) Manifest::get('tasks.queue.shutdown-grace-period', self::QUEUE_DEFAULT_GRACE);
    }

    /** How long an in-flight schedule:run gets after supercronic stops launching ticks. */
    public static function schedulerGrace(): int
    {
        return (int) Manifest::get('tasks.scheduler.shutdown-grace-period', self::SCHEDULER_DEFAULT_GRACE);
    }

    public static function ssrGrace(): int
    {
        $value = Manifest::get('tasks.web.ssr');

        return is_array($value) ? (int) ($value['shutdown-grace-period'] ?? self::SSR_DEFAULT_GRACE) : self::SSR_DEFAULT_GRACE;
    }

    /**
     * Placement is by task presence, not flags: web and ssr are always web; queue and scheduler
     * ride whichever container hosts them, or nowhere when switched off.
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
     * The scheduler's stop OVERLAPS the others' (supercronic is signalled the moment SIGTERM
     * lands, before the web entrypoint holds the ALB drain), so the budget is the slower of
     * the two tracks — drain plus the slowest non-scheduler program, or the scheduler's grace
     * — never their sum. Only web has a drain window. Overcommitting the Fargate cap is a
     * manifest error, not a silent clamp: clamping would promise a program a window ECS cuts off.
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
