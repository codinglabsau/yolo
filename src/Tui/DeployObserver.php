<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui;

/**
 * Reads in-flight rollouts straight from the gathered ECS service statuses, so a
 * deploy shows up in the dashboard whoever triggered it — `yolo deploy` in another
 * shell, CI, even a raw update-service. The global bar uses banner() to flag a
 * rollout; the Deployments tab uses active() to switch to the live rollout view.
 */
class DeployObserver
{
    /**
     * The service groups whose primary deployment is mid-rollout.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, array<string, mixed>>
     */
    public static function inProgress(array $statuses): array
    {
        return array_values(array_filter(
            $statuses,
            static fn (array $status): bool => ($status['rolloutState'] ?? null) === 'IN_PROGRESS',
        ));
    }

    /**
     * Is any group mid-rollout right now?
     *
     * @param  array<int, array<string, mixed>>  $statuses
     */
    public static function active(array $statuses): bool
    {
        return self::inProgress($statuses) !== [];
    }

    /**
     * A compact one-line rollout summary for the global bar (`deploying web 2/3`),
     * or null when nothing is rolling.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     */
    public static function banner(array $statuses): ?string
    {
        $rolling = self::inProgress($statuses);

        if ($rolling === []) {
            return null;
        }

        $parts = array_map(
            static fn (array $status): string => sprintf('%s %d/%d', $status['group']->value, (int) $status['running'], (int) $status['desired']),
            $rolling,
        );

        return 'deploying ' . implode(', ', $parts);
    }
}
