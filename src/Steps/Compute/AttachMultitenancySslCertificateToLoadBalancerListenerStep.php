<?php

namespace Codinglabs\Yolo\Steps\Compute;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class AttachMultitenancySslCertificateToLoadBalancerListenerStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::CONDITIONAL;
        }

        try {
            AwsResources::listenerCertificate(
                AwsResources::loadBalancerListenerOnPort(443)['ListenerArn'],
                AwsResources::certificate($this->config['apex'])['CertificateArn']
            );
        } catch (ResourceDoesNotExistException) {
            Aws::elasticLoadBalancingV2()->addListenerCertificates([
                'Certificates' => [
                    [
                        'CertificateArn' => AwsResources::certificate($this->config['apex'])['CertificateArn'],
                    ],
                ],
                'ListenerArn' => AwsResources::loadBalancerListenerOnPort(443)['ListenerArn'],
            ]);
        }

        return StepResult::SYNCED;
    }
}
