<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;

class SyncNginxConfigurationStep implements RunsOnAwsWeb
{
    public function __invoke(): StepResult
    {
        // drop the default nginx vhost, using -f force to suppress file does not exist error
        (Process::fromShellCommandline(
            command: <<<'sh'
                rm -f /etc/nginx/sites-available/default
                rm -f /etc/nginx/sites-enabled/default
            sh
        ))->mustRun();

        // add enhanced logging format
        file_put_contents(
            '/etc/nginx/conf.d/enhanced-logging.conf',
            file_get_contents(Paths::stubs('nginx/enhanced-logging.conf.stub'))
        );

        // forwarding server configuration template for sites on www.
        $forwardNonWwwTemplate = file_get_contents(Paths::stubs("nginx/forward_non_www.stub"));

        // main nginx vhost
        $vhostTemplate = Manifest::get('aws.ec2.octane')
            ? file_get_contents(Paths::stubs('nginx/www_octane.stub'))
            : file_get_contents(Paths::stubs('nginx/www.stub'));

        // the directory where the app is located; ie. /var/www/$name
        $name = Manifest::name();

        // create a catch-all vhost with forwarding rules
        file_put_contents(
            "/etc/nginx/sites-available/$name",
            str_replace(
                search: [
                    '{NAME}',
                    '{FORWARD_NON_WWW}',
                ],
                replace: [
                    $name,
                    collect(Manifest::tenants())
                        ->map(function (array $tenant) use ($forwardNonWwwTemplate) {
                            if ($tenant['subdomain']) {
                                // skip creating a non-www forwarding rule for subdomains
                                return "#{$tenant['domain']} is a subdomain, skipping non-www forwarding";
                            }

                            return str_replace(
                                search: [
                                    '{NON_WWW_FQDN}',
                                    '{WWW_FQDN}',
                                ],
                                replace: [
                                    $tenant['domain'],
                                    "www." . $tenant['domain'],
                                ],
                                subject: $forwardNonWwwTemplate
                            );
                        })
                        ->join("\n"),
                ],
                subject: $vhostTemplate
            )
        );

        // create a symbolic link to enable the app vhost, using -f force to supress file exists error
        (Process::fromShellCommandline(
            command: "ln -sf /etc/nginx/sites-available/$name /etc/nginx/sites-enabled/"
        ))->mustRun();

        return StepResult::SYNCED;
    }
}
