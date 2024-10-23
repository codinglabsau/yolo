<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Paths;
use Symfony\Component\Process\Process;

trait ExecutesCommands
{
    public function __construct(protected string $environment, protected string $command)
    {

    }

    public function __invoke(): void
    {
        $process = str_contains($this->command, 'tenants:artisan')
            // do not try and escape arguments in tenants:artisan commands
            ? Process::fromShellCommandline($this->command)
            : new Process(
                command: explode(' ', $this->command),
                cwd: Paths::base(),
                env: [],
                timeout: null
            );

        $process->mustRun();
    }

    public function name(): string
    {
        return $this->command;
    }
}
