<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RegistersAws;
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;

class EnsureManifestExistsStep implements Step
{
    use RegistersAws;

    public function __invoke(): StepResult
    {
        if (file_exists(Paths::base('yolo.yml'))) {
            return StepResult::SUCCESS;
        }

        $this->initialiseManifest();
        $this->registerAwsServices();

        return StepResult::CREATED;
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

        if ($s3Bucket = text("What is the name of the S3 bucket used for app storage?", placeholder: "Leave blank to skip"))  {
            Manifest::put('aws.bucket', $s3Bucket);
        }
    }
}
