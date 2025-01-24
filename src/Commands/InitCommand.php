<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\confirm;

class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Create the yolo.yml manifest in the current app root')
            ->addOption('dry-run', null, null, 'Run the command without making changes');
    }

    public function handle(): void
    {
        if (Manifest::exists()) {
            if (! confirm("A yolo.yml manifest already exists in the current directory. Do you want to overwrite it?", default: false)) {
                return;
            }
        }

        intro("Initialising yolo.yml");

        $this->gitIgnoreFilesAndDirectories();
        $this->initialiseManifest();

        info('Manifest generated successfully.');
    }

    protected function initialiseManifest(): void
    {
        file_put_contents(
            Paths::base('yolo.yml'),
            str_replace(
                search: [
                    '{NAME}',
                    '{AWS_ACCOUNT_ID}',
                    '{AWS_REGION}',
                ],
                replace: [
                    text('What is the name of this app?', placeholder: 'eg. codinglabs'),
                    text('What is the account ID of the AWS account you want to deploy to?'),
                    text('Which AWS region do you want to deploy to?', default: env('AWS_DEFAULT_REGION', 'ap-southeast-2')),
                ],
                subject: file_get_contents(Paths::stubs('yolo.yml.stub'))
            )
        );

        if (confirm("Is the app multi-tenant?", default: false)) {
            Manifest::put('tenants', [
                'tenant-id' => ['domain' => 'tenant-domain.tld']
            ]);

            Manifest::put('deploy', [
                'php artisan migrate --path=database/migrations/landlord --force',
                'php artisan tenants:artisan "migrate --path=database/migrations/tenant --database=tenant --force"',
            ]);
        } else {
            Manifest::put('domain', text("What is the domain?", placeholder: 'eg. codinglabs.com.au'));

            Manifest::put('deploy', [
                'php artisan migrate --force',
            ]);
        }

        if ($s3Bucket = text("What is the name of the S3 bucket used for app storage?", placeholder: "Leave blank to skip")) {
            Manifest::put('aws.bucket', $s3Bucket);
        }
    }

    protected function gitIgnoreFilesAndDirectories(): void
    {
        if (file_exists(Paths::base('.gitignore'))) {
            file_put_contents(
                Paths::base('.gitignore'),
                ".yolo" . PHP_EOL .
                ".env.staging" . PHP_EOL .
                ".env.production" . PHP_EOL,
                FILE_APPEND
            );
        }
    }
}
