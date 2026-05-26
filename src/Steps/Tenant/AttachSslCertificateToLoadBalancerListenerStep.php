<?php

namespace Codinglabs\Yolo\Steps\Tenant;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Acm;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Resources\Fargate\LoadBalancer;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class AttachSslCertificateToLoadBalancerListenerStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        $certificate = Acm::certificate($this->config['apex']);

        while ($certificate['Status'] !== 'ISSUED') {
            // take a little snooze until the certificate is issued
            sleep(2);

            $certificate = Acm::certificate($this->config['apex']);
        }

        $listenerArn = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443)['ListenerArn'];

        try {
            ElbV2::listenerCertificate($listenerArn, $certificate['CertificateArn']);
        } catch (ResourceDoesNotExistException) {
            Aws::elasticLoadBalancingV2()->addListenerCertificates([
                'ListenerArn' => $listenerArn,
                'Certificates' => [
                    ['CertificateArn' => $certificate['CertificateArn']],
                ],
            ]);
        }

        return StepResult::SYNCED;
    }
}
