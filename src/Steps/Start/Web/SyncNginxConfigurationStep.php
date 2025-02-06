<?php

namespace Codinglabs\Yolo\Steps\Start\Web;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;
use Codinglabs\Yolo\Concerns\DetectsSubdomains;

class SyncNginxConfigurationStep implements RunsOnAwsWeb
{
    use DetectsSubdomains;

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

        $filename = Helpers::keyedResourceName();

        // create a catch-all vhost with redirecting rules
        file_put_contents(
            "/etc/nginx/sites-available/$filename",
            str_replace(
                search: [
                    '{NAME}',
                    '{FORWARDING_RULES}',
                    '{SERVER_NAME}',
                ],
                replace: [
                    Manifest::name(),
                    $this->forwardingRules(),
                    $this->serverName(),
                ],
                subject: $vhostTemplate
            )
        );

        // create a symbolic link to enable the app vhost, using -f force to supress file exists error
        (Process::fromShellCommandline(
            command: "ln -sf /etc/nginx/sites-available/$filename /etc/nginx/sites-enabled/"
        ))->mustRun();

        return StepResult::SYNCED;
    }

    protected function forwardingRules(): string
    {
        if (Manifest::isMultitenanted()) {
            return collect(Manifest::tenants())
                ->map(function (array $tenant) {
                    if (! $this->domainHasWwwSubdomain($tenant['apex'], $tenant['domain'])) {
                        return sprintf("# %s is a subdomain, skipping redirects", $tenant['domain']);
                    }

                    $redirectTemplate = file_get_contents(Paths::stubs('nginx/redirect'));

                    return str_replace(
                        search: [
                            '{FROM}',
                            '{TO}',
                        ],
                        replace: [
                            str_starts_with($tenant['domain'], 'www.')
                                ? $tenant['apex']
                                : "www.{$tenant['domain']}",
                            str_starts_with($tenant['domain'], 'www.')
                                ? $tenant['domain']
                                : $tenant['apex'],
                        ],
                        subject: $redirectTemplate
                    );
                })
                ->join("\n");
        }

        if (! $this->domainHasWwwSubdomain(Manifest::get('apex'), Manifest::get('domain'))) {
            return sprintf("# %s is a subdomain, skipping redirects", Manifest::get('domain'));
        }

        $redirectTemplate = file_get_contents(Paths::stubs('nginx/redirect'));

        $apex = Manifest::apex();
        $domain = Manifest::get('domain');

        return str_replace(
            search: [
                '{FROM}',
                '{TO}',
            ],
            replace: [
                str_starts_with($domain, 'www.')
                    ? $apex
                    : "www.$domain",
                str_starts_with($domain, 'www.')
                    ? $domain
                    : $apex,
            ],
            subject: $redirectTemplate
        );
    }

    protected function serverName(): string
    {
        return Manifest::isMultitenanted()
            ? implode(' ', collect(Manifest::tenants())->pluck('domain')->toArray())
            : Manifest::get('domain');
    }
}
