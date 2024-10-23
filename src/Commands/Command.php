<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Concerns\RegistersAws;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

abstract class Command extends SymfonyCommand
{
    use RegistersAws;

    public InputInterface $input;
    public OutputInterface $output;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Helpers::app()->instance('input', $this->input = $input);
        Helpers::app()->instance('output', $this->output = $output);

        $this->input->hasArgument('environment')
            ? Helpers::app()->instance('environment', $this->argument('environment'))
            : Helpers::app()->instance('environment', 'production');

        $this->output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);

        if (! $this instanceof InitCommand) {
            $this->registerAwsServices();
        }

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
