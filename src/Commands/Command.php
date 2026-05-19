<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Concerns\RegistersAws;
use Codinglabs\Yolo\Concerns\HasAfterCallbacks;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Concerns\ChecksIfCommandsShouldBeRunning;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\error;

abstract class Command extends SymfonyCommand
{
    use ChecksIfCommandsShouldBeRunning;
    use HasAfterCallbacks;
    use RegistersAws;

    public InputInterface $input;

    public OutputInterface $output;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Helpers::app()->instance('input', $this->input = $input);
        Helpers::app()->instance('output', $this->output = $output);
        Helpers::app()->singleton('runningInAws', fn () => static::detectAwsEnvironment());

        // bail if command should not be running
        if (! $this->shouldBeRunning($this)) {
            error(sprintf("Cannot run '%s' in current environment", $this->getName()));

            return 1;
        }

        // special handling for `yolo init` command to execute early
        if ($this instanceof InitCommand) {
            Helpers::app()->instance('environment', 'production');

            return (int) (Helpers::app()->call([$this, 'handle']) ?: 0);
        }

        if (! Manifest::exists()) {
            error("Could not find yolo.yml manifest in the current directory - run 'yolo init' to create one");

            return 1;
        }

        if (! Manifest::environmentExists($this->argument('environment'))) {
            error(sprintf("Could not find '%s' in the YOLO manifest", $this->argument('environment')));

            return 1;
        }

        Helpers::app()->instance('environment', $this->argument('environment'));

        if (! Aws::runningInAws() && ! Helpers::keyedEnv('AWS_PROFILE')) {
            error(sprintf('You need to specify YOLO_%s_AWS_PROFILE in your .env file before proceeding', strtoupper(Helpers::environment())));

            return 1;
        }

        $this->registerAwsServices();

        $this->ensureManifestAccountMatchesProfile();

        // todo: remove once mvp is finished
        $this->output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);

        $exitCode = (int) (Helpers::app()->call([$this, 'handle']) ?: 0);

        foreach ($this->after as $closure) {
            $closure();
        }

        return $exitCode;
    }

    protected function ensureManifestAccountMatchesProfile(): void
    {
        $expected = Manifest::get('aws.account-id');

        if (! $expected) {
            return;
        }

        try {
            $actual = Aws::sts()->getCallerIdentity()['Account'];
        } catch (\Throwable $e) {
            throw new IntegrityCheckException(
                sprintf('Failed to verify AWS account via STS: %s', $e->getMessage()),
                previous: $e,
            );
        }

        if ($expected !== $actual) {
            throw new IntegrityCheckException(sprintf(
                'AWS account mismatch: manifest declares %s, YOLO_%s_AWS_PROFILE resolves to %s. Check .env.',
                $expected,
                strtoupper(Helpers::environment()),
                $actual,
            ));
        }
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
