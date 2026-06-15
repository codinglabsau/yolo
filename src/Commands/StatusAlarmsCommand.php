<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersIncidentReads;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

/**
 * The app's CloudWatch alarms and their state — the incident read surface for
 * "is anything actually firing". Exits non-zero when any alarm is in ALARM, so
 * it doubles as a health probe; `--json` is the form the `/yolo` skill consumes.
 */
class StatusAlarmsCommand extends Command implements ReadOnlyCommand
{
    use RendersIncidentReads;

    protected function configure(): void
    {
        $this
            ->setName('status:alarms')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the alarm state as JSON and exit (machine-readable; for the /yolo skill and scripts)')
            ->setDescription("Show the app's CloudWatch alarms and their state");
    }

    public function handle(): int
    {
        $alarms = static::gatherAlarms();

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'app' => Manifest::current()['name'] ?? null,
                'environment' => $this->argument('environment'),
                'alarms' => $alarms,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return static::anyAlarmFiring($alarms) ? 1 : 0;
        }

        if ($alarms === []) {
            info(sprintf("No alarms for this app in '%s'.", $this->argument('environment')));

            return self::SUCCESS;
        }

        intro(sprintf('yolo status:alarms · %s · %s', Manifest::current()['name'] ?? '', $this->argument('environment')));

        foreach ($this->alarmLines($alarms) as $line) {
            $this->output->writeln($line);
        }

        return static::anyAlarmFiring($alarms) ? 1 : 0;
    }
}
