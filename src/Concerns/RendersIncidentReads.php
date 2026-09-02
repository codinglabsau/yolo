<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Every read is defensive (a missing log group / service / alarm yields an empty
 * list, never a crash) so the surface works on a half-provisioned or cold app.
 */
trait RendersIncidentReads
{
    /**
     * @return array<int, array{group: string, events: array<int, array{timestamp: int, message: string}>}>
     */
    protected static function gatherLogs(int $limit = 60): array
    {
        $logGroup = (new TaskLogGroup())->name();

        return array_map(fn (ServerGroup $group): array => [
            'group' => $group->value,
            'events' => array_map(
                fn (array $event): array => [
                    'timestamp' => (int) ($event['timestamp'] ?? 0),
                    'message' => rtrim((string) ($event['message'] ?? '')),
                ],
                CloudWatchLogs::recent($logGroup, $group->value, $limit),
            ),
        ], Manifest::serverGroups());
    }

    /**
     * @return array<int, array{group: string, events: array<int, array{createdAt: ?string, message: string}>}>
     */
    protected static function gatherServiceEvents(int $limit = 10): array
    {
        $cluster = (new EcsCluster())->name();

        return array_map(function (ServerGroup $group) use ($cluster, $limit): array {
            try {
                $service = Ecs::service($cluster, (new EcsService($group))->name());
            } catch (ResourceDoesNotExistException) {
                return ['group' => $group->value, 'events' => []];
            }

            $events = array_slice($service['events'] ?? [], 0, $limit);

            return [
                'group' => $group->value,
                'events' => array_map(fn (array $event): array => [
                    'createdAt' => static::eventTimestamp($event['createdAt'] ?? null),
                    'message' => (string) ($event['message'] ?? ''),
                ], $events),
            ];
        }, Manifest::serverGroups());
    }

    /**
     * @return array<int, array{name: string, state: ?string, reason: ?string}>
     */
    protected static function gatherAlarms(): array
    {
        return CloudWatch::alarmsWithPrefix(Helpers::keyedResourceName());
    }

    /**
     * AWS timestamps arrive as the SDK's DateTimeResult, not scalars.
     */
    protected static function eventTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_int($value)) {
            return gmdate('c', $value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<int, array{group: string, events: array<int, array{timestamp: int, message: string}>}>  $groups
     * @return array<int, string>
     */
    protected function logLines(array $groups): array
    {
        $lines = [];

        foreach ($groups as $group) {
            $lines[] = sprintf('  <options=bold>%s</>', $group['group']);

            if ($group['events'] === []) {
                $lines[] = '  <fg=gray>no recent log events</>';

                continue;
            }

            foreach ($group['events'] as $event) {
                $lines[] = sprintf('  <fg=gray>%s</> %s', gmdate('H:i:s', $event['timestamp'] === 0 ? 0 : (int) ($event['timestamp'] / 1000)), $event['message']);
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, array{group: string, events: array<int, array{createdAt: ?string, message: string}>}>  $groups
     * @return array<int, string>
     */
    protected function serviceEventLines(array $groups): array
    {
        $lines = [];

        foreach ($groups as $group) {
            $lines[] = sprintf('  <options=bold>%s</>', $group['group']);

            if ($group['events'] === []) {
                $lines[] = '  <fg=gray>no recent service events</>';

                continue;
            }

            foreach ($group['events'] as $event) {
                $lines[] = sprintf('  <fg=gray>%s</> %s', $event['createdAt'] ?? '—', $event['message']);
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, array{name: string, state: ?string, reason: ?string}>  $alarms
     * @return array<int, string>
     */
    protected function alarmLines(array $alarms): array
    {
        return array_map(fn (array $alarm): string => sprintf(
            '  %s %s%s',
            static::formatAlarmState($alarm['state']),
            $alarm['name'],
            $alarm['reason'] === null ? '' : sprintf(' <fg=gray>— %s</>', $alarm['reason']),
        ), $alarms);
    }

    public static function formatAlarmState(?string $state): string
    {
        return match ($state) {
            'OK' => '<fg=green>OK   </>',
            'ALARM' => '<fg=red>ALARM</>',
            default => '<fg=gray>  ?  </>',
        };
    }

    /**
     * The non-zero exit signal for the incident read commands, so they double as health probes.
     *
     * @param  array<int, array{name: string, state: ?string, reason: ?string}>  $alarms
     */
    public static function anyAlarmFiring(array $alarms): bool
    {
        foreach ($alarms as $alarm) {
            if ($alarm['state'] === 'ALARM') {
                return true;
            }
        }

        return false;
    }
}
