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
 * The incident read surfaces behind `status:logs` / `status:events` /
 * `status:alarms` — thin, app-tier reads of CloudWatch Logs, ECS service events
 * and CloudWatch alarm state, with matching `--json` and display renderers. Every
 * read is defensive (a missing log group / service / alarm yields an empty list,
 * never a crash) so the surface works on a half-provisioned or cold app.
 */
trait RendersIncidentReads
{
    /**
     * Recent log events per server group, oldest → newest.
     *
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
     * Recent ECS service events per server group (the deploy/placement narrative
     * ECS keeps), newest first.
     *
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
     * The app's CloudWatch alarms and their current state.
     *
     * @return array<int, array{name: string, state: ?string, reason: ?string}>
     */
    protected static function gatherAlarms(): array
    {
        return CloudWatch::alarmsWithPrefix(Helpers::keyedResourceName());
    }

    /**
     * An AWS timestamp (the SDK's DateTimeResult, or an int/string) as an ISO-8601
     * string for the JSON contract, or null.
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

    // --- Rendering (instance — uses $this->output) --------------------------

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

    /**
     * `OK` (green), `ALARM` (red), `INSUFFICIENT_DATA`/unknown (gray). Pure.
     */
    public static function formatAlarmState(?string $state): string
    {
        return match ($state) {
            'OK' => '<fg=green>OK   </>',
            'ALARM' => '<fg=red>ALARM</>',
            default => '<fg=gray>  ?  </>',
        };
    }

    /**
     * True when any alarm is in ALARM — the non-zero exit signal for the incident
     * read commands, so they double as health probes.
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
