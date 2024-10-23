<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Symfony\Component\Process\Process;

class SyncPhpConfigurationStep implements RunsOnAws
{
    public function __invoke(): StepResult
    {
        // .ini for PHP CLI
        file_put_contents(
            "/etc/php/8.3/mods-available/{NAME}_cli.ini",
            file_get_contents(Paths::stubs('php/{NAME}_cli.ini.stub'))
        );

        // .ini for PHP FPM
        file_put_contents(
            "/etc/php/8.3/mods-available/{NAME}_fpm.ini",
            file_get_contents(Paths::stubs('php/{NAME}_fpm.ini.stub'))
        );

        // configuration for PHP-FPM pool
        file_put_contents(
            "/etc/php/8.3/fpm/pool.d/www_processes.conf",
            file_get_contents(Paths::stubs('php/www_processes.conf.stub'))
        );

        // enable mods
        (Process::fromShellCommandline(
            command: <<<'sh'
                phpenmod -s cli {NAME}_cli
                phpenmod -s fpm {NAME}_fpm
            sh
        ))->mustRun();

        return StepResult::SYNCED;
    }
}
