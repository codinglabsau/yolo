<?php

namespace Codinglabs\Yolo\Steps\Ami;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class CreateLaunchTemplateVersionStep implements Step
{
    public function __invoke(array $options): string|StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            $launchTemplate = AwsResources::launchTemplate();

            $launchTemplateVersion = Aws::ec2()->createLaunchTemplateVersion([
                'LaunchTemplateId' => $launchTemplate['LaunchTemplateId'],
                'LaunchTemplateData' => [
                    ...AwsResources::launchTemplatePayload()['LaunchTemplateData'],
                    'ImageId' => $options['ami-id'],
                ],
            ])['LaunchTemplateVersion'];

            // set the updated version as the default
            Aws::ec2()->modifyLaunchTemplate([
                'LaunchTemplateId' => $launchTemplate['LaunchTemplateId'],
                'DefaultVersion' => $launchTemplateVersion['VersionNumber'],
            ]);

            // refresh the statically defined launch template to reference the new version
            AwsResources::launchTemplate(refresh: true);

            return sprintf('version %s', $launchTemplateVersion['VersionNumber']);
        }

        return StepResult::WOULD_CREATE;
    }
}
