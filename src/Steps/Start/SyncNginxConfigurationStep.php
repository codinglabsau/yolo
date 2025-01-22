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
            file_get_contents(Paths::stubs('nginx/enhanced-logging.conf'))
        );

        // main nginx vhost
        $vhostTemplate = Manifest::get('aws.ec2.octane')
            ? file_get_contents(Paths::stubs('nginx/vhost_octane'))
            : file_get_contents(Paths::stubs('nginx/vhost'));

        $name = Manifest::name();

        // create a catch-all vhost with forwarding rules
        file_put_contents(
            "/etc/nginx/sites-available/$name",
            str_replace(
                search: [
                    '{NAME}',
                    '{FORWARDING_RULES}',
                    '{SERVER_NAME}',
                ],
                replace: [
                    $name,
                    $this->forwardingRules(),
                    $this->serverName(),
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

    protected function forwardingRules(): string
    {
        if (Manifest::isMultitenanted()) {
            return collect(Manifest::tenants())
                ->map(function (array $tenant) {
                    if ($tenant['subdomain']) {
                        // skip creating a non-www forwarding rule for subdomains
                        return "#{$tenant['domain']} is a subdomain, skipping non-www forwarding";
                    }

                    $forwardingTemplate = $tenant['www']
                        ? file_get_contents(Paths::stubs("nginx/forward_non_www"))
                        : file_get_contents(Paths::stubs("nginx/forward_www"));

                    return str_replace(
                        search: [
                            '{NON_WWW_FQDN}',
                            '{WWW_FQDN}',
                        ],
                        replace: [
                            $tenant['domain'],
                            "www." . $tenant['domain'],
                        ],
                        subject: $forwardingTemplate
                    );
                })
                ->join("\n");
        }

        $forwardingTemplate = Manifest::get('www')
            ? file_get_contents(Paths::stubs("nginx/forward_non_www"))
            : file_get_contents(Paths::stubs("nginx/forward_www"));

        return str_replace(
            search: [
                '{NON_WWW_FQDN}',
                '{WWW_FQDN}',
            ],
            replace: [
                Manifest::get('domain'),
                "www." . Manifest::get('domain'),
            ],
            subject: $forwardingTemplate
        );
    }

    protected function serverName(): string
    {
        return Manifest::isMultitenanted()
            ? '_'
            : Manifest::get('domain');
    }
}
