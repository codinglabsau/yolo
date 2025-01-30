<?php

namespace Codinglabs\Yolo\Steps\Start\All;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Contracts\RunsOnAws;

class SyncPhpConfigurationStep implements RunsOnAws
{
    public function __invoke(): StepResult
    {
        // .ini for PHP CLI
        file_put_contents(
            "/etc/php/8.3/mods-available/yolo_cli.ini",
            file_get_contents(Paths::stubs('php/cli.ini.stub'))
        );

        // .ini for PHP FPM
        file_put_contents(
            "/etc/php/8.3/mods-available/yolo_fpm.ini",
            file_get_contents(Paths::stubs('php/fpm.ini.stub'))
        );

        // configuration for PHP-FPM pool
        file_put_contents(
            "/etc/php/8.3/fpm/pool.d/yolo_www_processes.conf",
            file_get_contents(Paths::stubs('php/www_processes.conf.stub'))
        );

        // enable mods
        (Process::fromShellCommandline(
            command: <<<'sh'
                phpenmod -s cli yolo_cli
                phpenmod -s fpm yolo_fpm
            sh
        ))->mustRun();

        return StepResult::SYNCED;
    }
}
