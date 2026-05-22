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
        // already set them — so the app "just works" with zero queue config but
        // can still override. YOLO owns the SQS queue it provisions, so it knows
        // the exact name + URL; the app never has to (and can't accidentally point
        // at the wrong queue). No static AWS keys — the task role carries SQS access.
        $defaults = [
            'QUEUE_CONNECTION' => 'sqs',
            'AWS_DEFAULT_REGION' => Manifest::get('aws.region'),
            'SQS_PREFIX' => sprintf('https://sqs.%s.amazonaws.com/%s', Manifest::get('aws.region'), Aws::accountId()),
        ];

        // Solo apps have one queue; in multitenancy the worker resolves the
        // per-tenant queue at runtime, so a single SQS_QUEUE would be wrong.
        if (! Manifest::isMultitenanted()) {
            $defaults['SQS_QUEUE'] = Helpers::keyedResourceName();
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
