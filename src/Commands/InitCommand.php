<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;

class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Create the yolo.yml manifest in the current app root');
    }

    public function handle(): void
    {
        note("Creating yolo.yml...");

        file_put_contents(
            Paths::base('yolo.yml'),
            str_replace(
                search: [
                    '{NAME}',
                    '{AWS_REGION}',
                ],
                replace: [
                    text('What is the name of this app?'),
                    text('Which AWS region do you want to deploy to?', default: env('AWS_DEFAULT_REGION', 'ap-southeast-2')),
                ],
                subject: file_get_contents(Paths::stubs('yolo.yml.stub'))
            )
        );

        if (confirm("Is the app multi-tenant?")) {
            Manifest::put('tenants', [
                'tenant-id' => ['domain' => 'tenant-domain.tld']
            ]);

            Manifest::put('deploy', [
                'php artisan migrate --path=database/migrations/landlord --force',
                'php artisan tenants:artisan "migrate --path=database/migrations/tenant --database=tenant --force"',
            ]);
        } else {
            Manifest::put('domain', text("What is the domain?"));

            Manifest::put('deploy', [
                'php artisan migrate --force',
            ]);
        }

        info("YOLO initialised successfully");
    }
}
