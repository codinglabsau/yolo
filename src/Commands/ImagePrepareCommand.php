<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Steps;
use Illuminate\Support\Carbon;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\select;

class ImagePrepareCommand extends SteppedCommand
{
    use UsesEc2;

    protected array $steps = [
        // create new launch template version
        Steps\Image\CreateLaunchTemplateVersionStep::class,

        // scheduler group
        Steps\Image\CreateAutoScalingSchedulerGroupStep::class,

        // queue group
        Steps\Image\CreateAutoScalingQueueGroupStep::class,

        // web group
        Steps\Image\CreateAutoScalingWebGroupStep::class,
        Steps\Image\CreateWebGroupCpuAlarmsStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('image:prepare')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->addOption('ami-id', null, InputOption::VALUE_OPTIONAL, 'The AMI ID to prepare for service')
            ->setDescription('Prepare a new deployment group');
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

        $amiId = select(
            label: 'Which AMI do you want to use?',
            options: $amis,
            default: $this->option('ami-id') ?? array_key_first($amis),
        );

        $this->input->setOption('ami-id', $amiId);

        parent::handle();
    }
}
