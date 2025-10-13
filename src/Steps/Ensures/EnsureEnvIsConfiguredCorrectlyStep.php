<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Dotenv\Dotenv;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

class EnsureEnvIsConfiguredCorrectlyStep implements Step
{
    public function __construct(protected string $environment, protected $filesystem = new Filesystem()) {}

    public function __invoke(): StepResult
    {
        $dotenv = Dotenv::parse($this->filesystem->get(Paths::build('.env')));

        $this->checkAppVersion($dotenv);
        $this->checkAssetUrl($dotenv);

        if (Manifest::get('aws.mediaconvert')) {
            $this->checkMediaConvertConfiguration($dotenv);
        }

        return StepResult::SYNCED;
    }

    protected function checkAppVersion(array $dotenv): void
    {
        if (empty($dotenv['APP_VERSION'])) {
            $this->throwException($dotenv, 'APP_VERSION');
        }
    }

    protected function checkAssetUrl(array $dotenv): void
    {
        $expected = Paths::assetUrl($dotenv['APP_VERSION']);

        if ($dotenv['ASSET_URL'] !== $expected) {
            $this->throwException($dotenv, 'ASSET_URL', $expected);
        }
    }

    protected function checkMediaConvertConfiguration(array $dotenv): void
    {
        $expected = sprintf(
            'arn:aws:iam::%s:role/%s',
            Aws::accountId(),
            Helpers::keyedResourceName(Iam::MEDIA_CONVERT_ROLE),
        );

        if ($dotenv['AWS_MEDIACONVERT_ROLE_ID'] !== $expected) {
            $this->throwException($dotenv, 'AWS_MEDIACONVERT_ROLE_ID', $expected);
        }
    }

    /**
     * @throws IntegrityCheckException
     */
    protected function throwException(array $dotenv, string $key, ?string $expected = null): never
    {
        if ($expected === null) {
            throw new IntegrityCheckException("$key {$dotenv[$key]} is not set");
        }

        throw new IntegrityCheckException("$key {$dotenv[$key]} does not match $expected");
    }
}
