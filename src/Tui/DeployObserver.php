<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui;

/**
 * Reads rollouts from the gathered ECS statuses, so a deploy shows up whoever
 * triggered it — another shell, CI, even a raw update-service.
 */
class DeployObserver
{
    /**
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
     * @param  array<int, array<string, mixed>>  $statuses
     */
    public static function active(array $statuses): bool
    {
        return self::inProgress($statuses) !== [];
    }

    /**
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
