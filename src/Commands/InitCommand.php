<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;

class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment name', default: 'production')
            ->setDescription('Create the yolo.yml manifest in the current app root');
    }

    public function handle(): void
    {
        if (! Helpers::keyedEnv('AWS_PROFILE')) {
            error(sprintf("You need to specify YOLO_%s_AWS_PROFILE in your .env file before proceeding", strtoupper(Helpers::environment())));
        }

        if (! file_exists(Paths::base('yolo.yml'))) {
            info("Creating yolo.yml...");
            $this->initialiseManifest();
        } else {
            note("Skipping yolo.yml creation");
        }

        if (! Paths::s3ArtefactsBucket()) {
            note("Initialising artefacts bucket on S3...");
            $this->initialiseArtefactsBucket();
        }

        info("YOLO initialised successfully");
    }

    protected function initialiseManifest(): void
    {
        file_put_contents(
            Paths::base('yolo.yml'),
            str_replace(
                search: [
                    '{NAME}',
                    '{AWS_REGION}',
                ],
                replace: [
                    text('What is the name of this app?', placeholder: 'eg. codinglabs'),
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
    }

    protected function initialiseArtefactsBucket(): void
    {
        $bucketName = sprintf('%s-%s-yolo-artefacts', Manifest::name(), Helpers::environment());

        note("Creating S3 bucket {$bucketName}...");

        Aws::s3()->createBucket([
            'Bucket' => $bucketName,
        ]);

        Manifest::put('aws.artefacts-bucket', $bucketName);
    }
}
