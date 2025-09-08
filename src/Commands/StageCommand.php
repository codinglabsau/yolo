<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Steps;
use Illuminate\Support\Carbon;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;

class StageCommand extends SteppedCommand
{
    use UsesEc2;

    protected array $steps = [
        // create new launch template version
        Steps\Stage\CreateLaunchTemplateVersionStep::class,

        // web group
        Steps\Stage\ConfigureAutoScalingWebGroupStep::class,
        Steps\Stage\CreateWebGroupCpuAlarmsStep::class,

        // queue group
        Steps\Stage\ConfigureAutoScalingQueueGroupStep::class,

        // scheduler group
        Steps\Stage\ConfigureAutoScalingSchedulerGroupStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('stage')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->addOption('ami-id', null, InputOption::VALUE_OPTIONAL, 'The AMI ID to prepare for service')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Whether to perform an update')
            ->setDescription('Set the stage');
    }

    public function handle(): void
    {
        $amis = collect(Aws::ec2()->describeImages(['Owners' => ['self']])['Images'])
            ->filter(fn (array $image) => $image['State'] === 'available')
            ->sortByDesc('CreationDate')
            ->mapWithKeys(fn (array $image) => [
                $image['ImageId'] => sprintf(
                    '%s (%s) - created %s',
                    $image['Name'],
                    $image['ImageId'],
                    Carbon::parse($image['CreationDate'])
                        ->tz('Australia/Brisbane')
                        ->diffForHumans(),
                ),
            ])->toArray();

        $this->input->setOption('ami-id', select(
            label: 'Which AMI do you want to use?',
            options: $amis,
            default: $this->option('ami-id') ?? array_key_first($amis),
        ));

        if (! $this->option('update')) {
            $this->input->setOption('update', ! confirm('The --update option was not provided. This will create new resources. Are you sure?'));
        }

        parent::handle();
    }
}
