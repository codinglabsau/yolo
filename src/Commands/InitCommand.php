<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

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
        if (Manifest::exists()) {
            if (! confirm('A yolo.yml manifest already exists in the current directory. Do you want to overwrite it?', default: false)) {
                return;
            }
        }

        intro('Initialising yolo.yml');

        $this->gitIgnoreFilesAndDirectories();
        $this->initialiseManifest();
        $this->initialiseDockerfile();
        $this->initialiseDockerignore();
        $this->initialiseEnv();
        $this->ensureSessionManagerPlugin();

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

        if (confirm('Is the app multi-tenant?', default: false)) {
            Manifest::put('tenants', [
                'tenant-id' => ['domain' => 'tenant-domain.tld'],
            ]);

            Manifest::put('deploy', [
                'php artisan migrate --path=database/migrations/landlord --force',
                'php artisan tenants:artisan "migrate --path=database/migrations/tenant --database=tenant --force"',
            ]);
        } else {
            Manifest::put('domain', text('What is the domain?', placeholder: 'eg. codinglabs.com.au'));

            Manifest::put('deploy', [
                'php artisan migrate --force',
            ]);
        }

        if ($s3Bucket = text('What is the name of the S3 bucket used for app storage?', placeholder: 'Leave blank to skip')) {
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

    /**
     * `yolo run` opens a shell / runs one-off commands in a running container
     * via ECS Exec, which needs AWS's Session Manager plugin on this machine.
     * Offer to install it at setup so it's there before it's needed. (A future
     * `yolo doctor` will report its status alongside Docker / the AWS CLI.)
     */
    protected function ensureSessionManagerPlugin(): void
    {
        if ((new ExecutableFinder())->find('session-manager-plugin')) {
            info('AWS Session Manager plugin found.');

            return;
        }

        note("The AWS Session Manager plugin isn't installed — `yolo run` needs it to open a shell or run one-off commands in a running container.");

        if (PHP_OS_FAMILY === 'Darwin' && $this->input->isInteractive() && (new ExecutableFinder())->find('brew')) {
            if (confirm('Install it now with Homebrew? (you may be prompted for your password)', default: true)) {
                (new Process(['brew', 'install', '--cask', 'session-manager-plugin']))
                    ->setTty(Process::isTtySupported())
                    ->setTimeout(null)
                    ->run();

                return;
            }
        }

        warning('Install it before using `yolo run`: https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html');
    }

    protected function gitIgnoreFilesAndDirectories(): void
    {
        if (file_exists(Paths::base('.gitignore'))) {
            file_put_contents(
                Paths::base('.gitignore'),
                '.yolo' . PHP_EOL .
                '.env.staging' . PHP_EOL .
                '.env.production' . PHP_EOL,
                FILE_APPEND
            );
        }
    }

    protected function initialiseEnv(): void
    {
        if (! file_exists(Paths::base('.env.production'))) {
            file_put_contents(
                Paths::base('.env.production'),
                'APP_ENV=production' . PHP_EOL .
                'APP_KEY=' . PHP_EOL .
                'APP_DEBUG=false' . PHP_EOL .
                FILE_APPEND
            );
        }
    }
}
