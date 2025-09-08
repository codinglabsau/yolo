<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Codinglabs\Yolo\Enums\ServerGroup;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Concerns\FormatsSshCommands;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class CommandCommand extends Command
{
    use FormatsSshCommands;
    use UsesEc2;

    protected function configure(): void
    {
        $this
            ->setName('command')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('command', null, InputArgument::OPTIONAL, 'The command to run')
            ->addOption('ssh-key', null, InputArgument::OPTIONAL, 'The SSH key to use')
            ->addOption('group', null, InputArgument::OPTIONAL, 'The server group to run the command in', default: ServerGroup::SCHEDULER->value)
            ->setDescription('Run a command in the given environment');
    }

    public function handle(): void
    {
        $command = $this->option('command') ?? text('Which command do you want to run?');

        $confirmed = confirm("Are you sure you want to run the command '$command' in group {$this->option('group')} on {$this->argument('environment')}?");

        if (! $confirmed) {
            info('🐥 yolo');

            return;
        }

        $serverGroups = str_contains($this->option('group'), ',')
            ? explode(',', $this->option('group'))
            : [$this->option('group')];

        foreach ($serverGroups as $serverGroup) {
            $instances = $this->findSshPrefixesForGroup(ServerGroup::from($serverGroup));

            info('Found ' . count($instances) . " instances in group $serverGroup on {$this->argument('environment')}");

            foreach ($instances as $ipAddress => $sshCommand) {
                warning("Executing command '$command' in group $serverGroup on instance $ipAddress on {$this->argument('environment')}...");

                $process = new Process(
                    command: [
                        ...explode(' ', $sshCommand),
                        sprintf("cd /var/www/%s && $command", Manifest::name()),
                    ],
                    cwd: Paths::base(),
                    env: [],
                    timeout: null
                );

                $process->run(function ($type, $buffer) {
                    echo $buffer;
                });
            }
        }
    }

    protected function findSshPrefixesForGroup(ServerGroup $serverGroup): array
    {
        $prefixes = [];

        foreach (static::ec2IpByName(name: Helpers::keyedResourceName($serverGroup, exclusive: false), firstOnly: false) as $ipAddress) {
            $prefixes[$ipAddress] = static::formatSshCommand(
                ipAddress: $ipAddress,
                sshKey: $this->option('ssh-key')
            );
        }

        return $prefixes;
    }
}
