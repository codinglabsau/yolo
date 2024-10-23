<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Concerns\RegistersAws;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use function Laravel\Prompts\error;

abstract class Command extends SymfonyCommand
{
    use RegistersAws;

    public InputInterface $input;
    public OutputInterface $output;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Helpers::app()->instance('input', $this->input = $input);
        Helpers::app()->instance('output', $this->output = $output);

        if (! $this->input->hasArgument('environment')) {
            $this->input->setArgument('environment', 'production');
        }

        Helpers::app()->instance('environment', $this->argument('environment'));

        if (Manifest::exists()) {
            if (! Manifest::environmentExists($this->argument('environment'))) {
                error("Could not find '{$this->argument('environment')}' in the YOLO manifest");
                return 1;
            }

            $this->registerAwsServices();
        }

        $this->output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);

        return (int)(Helpers::app()->call([$this, 'handle']) ?: 0);
    }

    protected function argument($key)
    {
        return $this->input->getArgument($key);
    }

    protected function option($key)
    {
        return $this->input->getOption($key);
    }
}
