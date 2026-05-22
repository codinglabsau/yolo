<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Contracts\Step;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;

class ConfigureEnvAndVersionStep implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options): void
    {
        $appVersion = Arr::get($options, 'app-version');
        $envPath = Paths::build(".env.$this->environment");

        $this->filesystem->put(
            Paths::version(),
            $appVersion
        );

        $values = [
            'APP_VERSION' => $appVersion,
            'AWS_MEDIACONVERT_ROLE_ID' => sprintf(
                'arn:aws:iam::%s:role/%s',
                Aws::accountId(),
                Helpers::keyedResourceName(Iam::MEDIA_CONVERT_ROLE),
            ),
        ];

        // Assets always live in S3 behind the YOLO-provisioned CloudFront
        // distribution. ASSET_URL points app-generated asset URLs at it,
        // versioned per build so each deploy's hashed bundle sits under its
        // own prefix and old builds keep resolving.
        $values['ASSET_URL'] = sprintf('https://%s/builds/%s', (new AssetDistribution())->domain(), $appVersion);

        // Fargate-sane defaults injected only when the consumer's .env doesn't
        // already set them — the app "just works" with zero config but can still
        // override.
        $defaults = [
            'AWS_DEFAULT_REGION' => Manifest::get('aws.region'),
        ];

        // tasks.web.queue is the single switch for "this app uses the SQS queue".
        // On: wire the connection to the queue YOLO provisions (it owns the name +
        // URL, so the app can't point at the wrong one) — the queue:work supervisor
        // program runs alongside. No static AWS keys; the task role carries access.
        // Off: force `sync` so jobs run inline rather than routing to a queue with
        // no worker consuming it (the framework default of `database` has the same
        // no-worker pitfall). Solo has one queue; multitenancy resolves the
        // per-tenant queue at runtime, so SQS_QUEUE is not pinned for it.
        if (Helpers::validateStrictBool(Manifest::get('tasks.web.queue', false), 'tasks.web.queue')) {
            $defaults['QUEUE_CONNECTION'] = 'sqs';
            $defaults['SQS_PREFIX'] = sprintf('https://sqs.%s.amazonaws.com/%s', Manifest::get('aws.region'), Aws::accountId());

            if (! Manifest::isMultitenanted()) {
                $defaults['SQS_QUEUE'] = Helpers::keyedResourceName();
            }
        } else {
            $defaults['QUEUE_CONNECTION'] = 'sync';
        }

        if (Manifest::has('aws.bucket')) {
            $defaults['AWS_BUCKET'] = Manifest::get('aws.bucket');
        }

        foreach ($defaults as $key => $value) {
            if (! $this->envDefines($envPath, $key)) {
                $values[$key] = $value;
            }
        }

        $this->filesystem->append($envPath, $this->generateValues($values));
    }

    protected function envDefines(string $path, string $key): bool
    {
        if (! $this->filesystem->exists($path)) {
            return false;
        }

        return preg_match('/^' . preg_quote($key, '/') . '=/m', $this->filesystem->get($path)) === 1;
    }

    protected function generateValues(array $values): string
    {
        $result = PHP_EOL . '# YOLO generated values' . PHP_EOL;

        foreach ($values as $key => $value) {
            $result .= "$key=$value" . PHP_EOL;
        }

        return $result;
    }
}
