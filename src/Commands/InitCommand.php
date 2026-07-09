<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\confirm;

class InitCommand extends Command
{
    protected string $appName;

    protected string $environment;

    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Create the yolo.yml manifest in the current app root');
    }

    public function handle(): void
    {
        if (Manifest::exists() && ! confirm('A yolo.yml manifest already exists in the current directory. Do you want to overwrite it?', default: false)) {
            return;
        }

        intro('Initialising yolo.yml');

        $this->appName = text('What is the name of this app?', placeholder: 'eg. codinglabs');
        $this->environment = text('Which environment do you want to add?', placeholder: 'eg. production', required: true);

        // Everything below writes under the chosen environment — the manifest block,
        // the starter env file, the gitignore entry — so bind it before they run.
        Helpers::app()->instance('environment', $this->environment);

        $this->gitIgnoreFilesAndDirectories();
        $this->initialiseManifest();
        $this->initialiseDockerfile();
        $this->initialiseDockerignore();
        $this->initialiseEnv();

        info('Manifest generated successfully.');

        $this->offerCredentialsSetup();
    }

    /**
     * The natural next step after scaffolding is authenticating the machine, so
     * offer `configure` inline — same pattern as the Session Manager plugin
     * offer. Init and configure stay separate commands because their cadences
     * differ (once per app vs once per machine per account): a dev joining an
     * existing app runs configure without ever running init, and an
     * already-configured machine scaffolding a second app declines here.
     */
    protected function offerCredentialsSetup(): void
    {
        if (! $this->input->isInteractive() || ! $this->getApplication() instanceof Application) {
            return;
        }

        if (! confirm(sprintf("Set up this machine's AWS credentials for %s now?", $this->environment), default: true)) {
            note(sprintf('Run `yolo configure %s` when you are ready.', $this->environment));

            return;
        }

        $exitCode = $this->getApplication()->find('configure')->run(
            new ArrayInput(['environment' => $this->environment]),
            $this->output,
        );

        if ($exitCode !== self::SUCCESS) {
            note(sprintf('Credential setup did not finish — re-run `yolo configure %s` any time.', $this->environment));
        }
    }

    protected function initialiseManifest(): void
    {
        file_put_contents(
            Paths::base('yolo.yml'),
            str_replace(
                search: [
                    '{NAME}',
                    '{ENVIRONMENT}',
                    '{AWS_ACCOUNT_ID}',
                    '{AWS_REGION}',
                ],
                replace: [
                    $this->appName,
                    $this->environment,
                    text('What is the account ID of the AWS account you want to deploy to?'),
                    text('Which AWS region do you want to deploy to?', default: env('AWS_DEFAULT_REGION', 'ap-southeast-2')),
                ],
                subject: file_get_contents(Paths::stubs('yolo.yml.stub'))
            )
        );

        if (confirm('Is the app multi-tenant?', default: false)) {
            Manifest::put('tenants', [
                'tenant-id' => ['domain' => 'tenant-domain.tld'],
            ]);

            Manifest::put('deploy', [
                'php artisan migrate --path=database/migrations/landlord --force',
                'php artisan tenants:artisan "migrate --path=database/migrations/tenant --database=tenant --force"',
            ]);
        } else {
            Manifest::put('domain', text('What is the domain?', placeholder: 'eg. example.com'));

            Manifest::put('deploy', [
                'php artisan migrate --force',
            ]);
        }
        $s3Bucket = text('What is the name of the S3 bucket used for app storage?', placeholder: 'Leave blank to skip');

        if ($s3Bucket !== '' && $s3Bucket !== '0') {
            Manifest::put('bucket', $s3Bucket);
        }
    }

    protected function initialiseDockerfile(): void
    {
        if (file_exists(Paths::base('Dockerfile'))
            && ! confirm('A Dockerfile already exists. Overwrite it with the YOLO default?', default: false)) {
            return;
        }

        copy(Paths::stubs('Dockerfile.stub'), Paths::base('Dockerfile'));
    }

    protected function initialiseDockerignore(): void
    {
        if (file_exists(Paths::base('.dockerignore'))
            && ! confirm('A .dockerignore already exists. Overwrite it with the YOLO default?', default: false)) {
            return;
        }

        copy(Paths::stubs('.dockerignore.stub'), Paths::base('.dockerignore'));
    }

    protected function gitIgnoreFilesAndDirectories(): void
    {
        if (! file_exists(Paths::base('.gitignore'))) {
            return;
        }

        // The chosen environment's .env file plus the common ones, deduped so a
        // `production`/`staging` environment doesn't list its pattern twice.
        $entries = collect([
            '.yolo',
            '.env.staging',
            '.env.production',
            '.env.' . $this->environment,
            '.env.environment.*',
            // env-manifest working copies (yolo-environment-production.yml
            // etc.) — never matches the app manifest yolo.yml
            'yolo-environment-*.yml',
        ])->unique()->implode(PHP_EOL);

        file_put_contents(Paths::base('.gitignore'), $entries . PHP_EOL, FILE_APPEND);
    }

    protected function initialiseEnv(): void
    {
        $envFile = '.env.' . $this->environment;

        if (! file_exists(Paths::base($envFile))) {
            file_put_contents(
                Paths::base($envFile),
                'APP_ENV=' . $this->environment . PHP_EOL .
                'APP_KEY=' . PHP_EOL .
                'APP_DEBUG=false' . PHP_EOL,
            );
        }
    }
}
