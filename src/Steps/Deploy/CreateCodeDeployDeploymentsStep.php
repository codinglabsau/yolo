<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;

class CreateCodeDeployDeploymentsStep implements Step
{
    use UsesCodeDeploy;

    public function __construct(protected string $environment, protected $filesystem = new Filesystem()) {}

    public function __invoke(): void
    {
        $appVersion = $this->filesystem->get(Paths::version());

        $this->createSchedulerServerDeployment($appVersion);
        $this->createQueueServerDeployment($appVersion);
        $this->createWebServerDeployment($appVersion);
    }

    protected function createSchedulerServerDeployment(string $appVersion): void
    {
        Aws::codeDeploy()->createDeployment([
            ...static::deploymentPayload($appVersion),
            ...[
                'deploymentGroupName' => Helpers::keyedResourceName('scheduler'),
            ],
        ]);
    }

    protected function createQueueServerDeployment(string $appVersion): void
    {
        Aws::codeDeploy()->createDeployment([
            ...static::deploymentPayload($appVersion),
            ...[
                'deploymentGroupName' => Helpers::keyedResourceName('queue'),
            ],
        ]);
    }

    protected function createWebServerDeployment(string $appVersion): void
    {
        Aws::codeDeploy()->createDeployment([
            ...static::deploymentPayload($appVersion),
            ...[
                'deploymentGroupName' => Helpers::keyedResourceName('web'),
            ],
        ]);
    }

    protected static function deploymentPayload(string $appVersion): array
    {
        return [
            'applicationName' => static::applicationName(),
            'description' => sprintf('Version %s deployed by %s', $appVersion, get_current_user()),
            'revision' => [
                'revisionType' => 'S3',
                's3Location' => [
                    'bucket' => Paths::s3ArtefactsBucket(),
                    'bundleType' => 'tgz',
                    'key' => Paths::s3Artefacts($appVersion, Helpers::artefactName()),
                ],
            ],
        ];
    }
}
