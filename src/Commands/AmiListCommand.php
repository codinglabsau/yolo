<?php

namespace Codinglabs\Yolo\Commands;

use Carbon\Carbon;
use Codinglabs\Yolo\Aws;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\table;

class AmiListCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('ami:list')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('List the available Amazon Machine Images in the given environment');
    }

    public function handle(): void
    {
        table(
            ['AMI Name', 'AMI ID', 'Visibility', 'State', 'Creation Date', 'Last Launched'],
            collect(Aws::ec2()->describeImages(['Owners' => ['self']])['Images'])
                ->sortByDesc('LastLaunchedTime')
                ->map(fn ($image) => [
                    $image['Name'],
                    $image['ImageId'],
                    $image['Public'] ? 'Public' : 'Private',
                    $image['State'],
                    Carbon::parse($image['CreationDate'])
                        ->tz('Australia/Brisbane')
                        ->format('d/m/Y H:i:s'),
                    isset($image['LastLaunchedTime'])
                        ? Carbon::parse($image['LastLaunchedTime'])
                        ->diffForHumans()
                        : 'Never',
                ])
        );
    }
}
