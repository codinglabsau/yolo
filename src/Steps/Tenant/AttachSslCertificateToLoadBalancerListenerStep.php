<?php

namespace Codinglabs\Yolo\Steps\Tenant;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class AttachSslCertificateToLoadBalancerListenerStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        $certificate = AwsLookups::certificate($this->config['apex']);

        if ($certificate['Status'] !== 'ISSUED') {
            do {
                $certificate = AwsLookups::certificate($this->config['apex']);

                // take a little snooze until the certificate is issued
                sleep(2);
            } while ($certificate['Status'] !== 'ISSUED');
        }

        try {
            AwsLookups::listenerCertificate(
                AwsLookups::loadBalancerListenerOnPort(443)['ListenerArn'],
                AwsLookups::certificate($this->config['apex'])['CertificateArn']
            );
        } catch (ResourceDoesNotExistException) {
            Aws::elasticLoadBalancingV2()->addListenerCertificates([
                'Certificates' => [
                    [
                        'CertificateArn' => AwsLookups::certificate($this->config['apex'])['CertificateArn'],
                    ],
                ],
                'ListenerArn' => AwsLookups::loadBalancerListenerOnPort(443)['ListenerArn'],
            ]);
        }

        return StepResult::SYNCED;
    }
}
