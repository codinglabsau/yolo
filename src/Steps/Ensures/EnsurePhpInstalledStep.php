<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Helpers;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Contracts\Step;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Concerns\FormatsSshCommands;

class EnsurePhpInstalledStep implements Step
{
    use FormatsSshCommands;

    public function __invoke(): string
    {
        return Str::of(
            Process::fromShellCommandline(
                command: static::formatSshCommand(
                    ipAddress: Helpers::app('amiIp'),
                    command: 'php -v'
                )
            )->mustRun()
                ->getOutput()
        )
            ->before('(cli)')
            ->trim()
            ->wrap(before: '<info>', after: '</info>');
    }
}
