<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Steps\Build\ConfigureEnvAndVersionStep;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;

class InitCommand extends Command
{
    /**
     * The commercial AWS regions, keyed by region code. Curated rather than
     * fetched — the SSM region listing needs credentials this command runs
     * without, and new regions appear rarely enough that a release keeps pace.
     */
    protected const array AWS_REGIONS = [
        'af-south-1' => 'af-south-1 — Cape Town',
        'ap-east-1' => 'ap-east-1 — Hong Kong',
        'ap-east-2' => 'ap-east-2 — Taipei',
        'ap-northeast-1' => 'ap-northeast-1 — Tokyo',
        'ap-northeast-2' => 'ap-northeast-2 — Seoul',
        'ap-northeast-3' => 'ap-northeast-3 — Osaka',
        'ap-south-1' => 'ap-south-1 — Mumbai',
        'ap-south-2' => 'ap-south-2 — Hyderabad',
        'ap-southeast-1' => 'ap-southeast-1 — Singapore',
        'ap-southeast-2' => 'ap-southeast-2 — Sydney',
        'ap-southeast-3' => 'ap-southeast-3 — Jakarta',
        'ap-southeast-4' => 'ap-southeast-4 — Melbourne',
        'ap-southeast-5' => 'ap-southeast-5 — Malaysia',
        'ap-southeast-6' => 'ap-southeast-6 — New Zealand',
        'ap-southeast-7' => 'ap-southeast-7 — Thailand',
        'ca-central-1' => 'ca-central-1 — Montreal',
        'ca-west-1' => 'ca-west-1 — Calgary',
        'eu-central-1' => 'eu-central-1 — Frankfurt',
        'eu-central-2' => 'eu-central-2 — Zurich',
        'eu-north-1' => 'eu-north-1 — Stockholm',
        'eu-south-1' => 'eu-south-1 — Milan',
        'eu-south-2' => 'eu-south-2 — Spain',
        'eu-west-1' => 'eu-west-1 — Ireland',
        'eu-west-2' => 'eu-west-2 — London',
        'eu-west-3' => 'eu-west-3 — Paris',
        'il-central-1' => 'il-central-1 — Tel Aviv',
        'me-central-1' => 'me-central-1 — UAE',
        'me-south-1' => 'me-south-1 — Bahrain',
        'mx-central-1' => 'mx-central-1 — Mexico',
        'sa-east-1' => 'sa-east-1 — São Paulo',
        'us-east-1' => 'us-east-1 — N. Virginia',
        'us-east-2' => 'us-east-2 — Ohio',
        'us-west-1' => 'us-west-1 — N. California',
        'us-west-2' => 'us-west-2 — Oregon',
    ];

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
                    select(
                        label: 'Which AWS region do you want to deploy to?',
                        options: self::AWS_REGIONS,
                        default: array_key_exists($preferredRegion = (string) env('AWS_DEFAULT_REGION', 'ap-southeast-2'), self::AWS_REGIONS)
                            ? $preferredRegion
                            : 'ap-southeast-2',
                        scroll: 12,
                    ),
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

        if (file_exists(Paths::base($envFile))) {
            note(sprintf('%s already exists — leaving it untouched.', $envFile));

            return;
        }

        if (! confirm(sprintf('Create a starter %s file?', $envFile), default: true)) {
            return;
        }

        file_put_contents(Paths::base($envFile), $this->starterEnvContents());

        info(sprintf('Created %s with a fresh APP_KEY.', $envFile));
        note(sprintf(
            'Review %s before deploying — fill in the app-specific values (database, mail, third-party keys), then upload it with `yolo env:push %s`.',
            $envFile,
            $this->environment,
        ));
    }

    /**
     * The starter env is the app's own .env.example corrected for the target
     * environment: APP_ENV, APP_DEBUG=false, a freshly minted APP_KEY (the same
     * base64 32-byte key `artisan key:generate` produces), and APP_URL when the
     * manifest declares a domain. Every AWS_* key and every platform-injected
     * key is stripped — ConfigureEnvAndVersionStep writes those from the
     * manifest at build time, so a copy here is drift at best and a build
     * failure at worst (the stock example's LOG_CHANNEL=stack conflicts with
     * the enforced stderr).
     */
    protected function starterEnvContents(): string
    {
        $overrides = collect([
            'APP_ENV' => $this->environment,
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false',
        ])->when(
            Manifest::has('domain'),
            fn ($overrides) => $overrides->put('APP_URL', 'https://' . Manifest::get('domain'))
        );

        if (! file_exists(Paths::base('.env.example'))) {
            return $overrides->map(fn ($value, $key): string => $key . '=' . $value)->implode(PHP_EOL) . PHP_EOL;
        }

        $contents = preg_replace(
            '/\n{3,}/',
            "\n\n",
            (string) preg_replace(
                sprintf('/^(?:AWS_[A-Z0-9_]*|%s)=.*\n?/m', implode('|', ConfigureEnvAndVersionStep::INJECTED_KEYS)),
                '',
                file_get_contents(Paths::base('.env.example'))
            )
        );

        foreach ($overrides as $key => $value) {
            $line = $key . '=' . $value;

            $contents = preg_match(sprintf('/^%s=.*$/m', $key), (string) $contents) === 1
                ? preg_replace_callback(sprintf('/^%s=.*$/m', $key), fn (): string => $line, (string) $contents)
                : rtrim((string) $contents, "\n") . "\n" . $line . "\n";
        }

        return (string) $contents;
    }
}
