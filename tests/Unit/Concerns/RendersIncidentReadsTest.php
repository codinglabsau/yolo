<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Commands\StatusLogsCommand;
use Codinglabs\Yolo\Commands\StatusAlarmsCommand;
use Codinglabs\Yolo\Commands\StatusEventsCommand;
use Codinglabs\Yolo\Concerns\RendersIncidentReads;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

function incidentProbe(): object
{
    return new class(new BufferedOutput())
    {
        use RendersIncidentReads;

        public function __construct(public OutputInterface $output) {}

        /** @return array<int, array{name: string, state: ?string, reason: ?string}> */
        public function alarms(): array
        {
            return self::gatherAlarms();
        }

        /**
         * @param  array<int, array{name: string, state: ?string, reason: ?string}>  $alarms
         * @return array<int, string>
         */
        public function alarmsRender(array $alarms): array
        {
            return $this->alarmLines($alarms);
        }

        /**
         * @param  array<int, array{group: string, events: array<int, array{timestamp: int, message: string}>}>  $groups
         * @return array<int, string>
         */
        public function logsRender(array $groups): array
        {
            return $this->logLines($groups);
        }

        /**
         * @param  array<int, array{group: string, events: array<int, array{createdAt: ?string, message: string}>}>  $groups
         * @return array<int, string>
         */
        public function eventsRender(array $groups): array
        {
            return $this->serviceEventLines($groups);
        }
    };
}

it('colours alarm state and detects a firing alarm', function (): void {
    expect(StatusAlarmsCommand::formatAlarmState('OK'))->toContain('OK')->toContain('green');
    expect(StatusAlarmsCommand::formatAlarmState('ALARM'))->toContain('ALARM')->toContain('red');
    expect(StatusAlarmsCommand::formatAlarmState('INSUFFICIENT_DATA'))->toContain('gray');
    expect(StatusAlarmsCommand::formatAlarmState(null))->toContain('gray');

    expect(StatusAlarmsCommand::anyAlarmFiring([['name' => 'a', 'state' => 'OK', 'reason' => null]]))->toBeFalse();
    expect(StatusAlarmsCommand::anyAlarmFiring([['name' => 'a', 'state' => 'ALARM', 'reason' => 'high']]))->toBeTrue();
});

it("gathers only the app's alarms, by name prefix", function (): void {
    writeManifest([]);

    $captured = [];
    bindMockCloudWatchClient(['DescribeAlarms' => new Result(['MetricAlarms' => [
        ['AlarmName' => 'yolo-testing-my-app-cpu', 'StateValue' => 'OK', 'StateReason' => 'within'],
        ['AlarmName' => 'yolo-testing-my-app-5xx', 'StateValue' => 'ALARM', 'StateReason' => 'errors'],
        ['AlarmName' => 'yolo-testing-other-app-cpu', 'StateValue' => 'OK', 'StateReason' => 'within'],
    ]])], $captured);

    $alarms = incidentProbe()->alarms();

    expect($alarms)->toHaveCount(2)
        ->and($alarms[0])->toBe(['name' => 'yolo-testing-my-app-cpu', 'state' => 'OK', 'reason' => 'within'])
        ->and($alarms[1]['state'])->toBe('ALARM');
});

it('renders the alarm, log and event panels', function (): void {
    $probe = incidentProbe();

    expect(implode("\n", $probe->alarmsRender([['name' => 'yolo-x-5xx', 'state' => 'ALARM', 'reason' => 'errors']])))
        ->toContain('ALARM')->toContain('yolo-x-5xx')->toContain('errors');

    expect(implode("\n", $probe->logsRender([['group' => 'web', 'events' => []]])))
        ->toContain('web')->toContain('no recent log events');

    expect(implode("\n", $probe->eventsRender([['group' => 'web', 'events' => [['createdAt' => '2026-06-15T00:00:00+00:00', 'message' => 'steady state']]]])))
        ->toContain('web')->toContain('steady state');
});

it('registers the incident read commands under the status namespace with --json', function (): void {
    foreach ([StatusLogsCommand::class, StatusEventsCommand::class, StatusAlarmsCommand::class] as $class) {
        $command = new $class();

        expect($command->getDefinition()->hasOption('json'))->toBeTrue()
            ->and($command->getDefinition()->hasArgument('environment'))->toBeTrue();
    }

    expect((new StatusLogsCommand())->getName())->toBe('status:logs')
        ->and((new StatusEventsCommand())->getName())->toBe('status:events')
        ->and((new StatusAlarmsCommand())->getName())->toBe('status:alarms');
});
