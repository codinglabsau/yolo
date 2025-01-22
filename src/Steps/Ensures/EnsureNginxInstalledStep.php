<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Concerns\FormatsSshCommands;

class EnsureNginxInstalledStep implements Step
{
    use FormatsSshCommands;

    public function __invoke(): string
    {
        return Str::of(Process::fromShellCommandline(
            command: static::formatSshCommand(
                ipAddress: Helpers::app('amiIp'),
                command: 'nginx -v'
            )
        )->mustRun()
            ->getOutput())
            ->trim()
            ->wrap(before: '<info>', after: '</info>');
    }
}
