<?php

namespace Codinglabs\Yolo\Commands;

use Carbon\Carbon;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Concerns\FormatsSshCommands;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\table;

class Ec2ListCommand extends Command
{
    use FormatsSshCommands;

    protected function configure(): void
    {
        $this
            ->setName('ec2:list')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('ssh-key', null, InputArgument::OPTIONAL, 'The SSH key to use')
            ->setDescription('List the EC2 instances in the given environment');
    }

    public function handle(): void
    {
        table(
            ['Name', 'Instance ID', 'Instance Type', 'Instance State', 'SSH', 'Public IPv4', 'Private IP adddress', 'Launch Time'],
            collect(Aws::ec2()->describeInstances()['Reservations'])
                ->flatMap(fn ($reservation) => $reservation['Instances'])
                ->filter(fn ($instance) => $instance['State']['Name'] !== 'terminated')
                ->sortByDesc('LaunchTime')
                ->map(fn ($instance) => [
                    collect($instance['Tags'])->firstWhere('Key', 'Name')['Value'],
                    $instance['InstanceId'],
                    $instance['InstanceType'],
                    $instance['State']['Name'],
                    isset($instance['PublicIpAddress'])
                        ? static::formatSshCommand(
                            ipAddress: $instance['PublicIpAddress'],
                            sshKey: $this->option('ssh-key'),
                        )
                        : '',
                    $instance['PublicIpAddress'] ?? '',
                    $instance['PrivateIpAddress'] ?? '',
                    Carbon::parse($instance['LaunchTime'])
                        ->tz('Australia/Brisbane')
                        ->format('d/m/Y H:i:s'),
                ])
        );
    }
}
