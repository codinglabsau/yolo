<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Fargate\HttpsListener;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncHttpsListenerStep implements ExecutesWebStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('apex') && ! Manifest::has('domain')) {
            return StepResult::SKIPPED;
        }

        try {
            $certificate = AwsResources::certificate(Manifest::apex());
        } catch (ResourceDoesNotExistException) {
            return StepResult::SKIPPED;
        }

        if ($certificate['Status'] !== 'ISSUED') {
            return StepResult::SKIPPED;
        }

        $listener = new HttpsListener($certificate);

        // Cert-attachment is orchestration, not part of the resource's identity.
        if ($listener->exists() && ! static::hasCertificate($listener->arn(), $certificate)) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            Aws::elasticLoadBalancingV2()->addListenerCertificates([
                'ListenerArn' => $listener->arn(),
                'Certificates' => [
                    ['CertificateArn' => $certificate['CertificateArn']],
                ],
            ]);
        }

        return $this->syncResource($listener, $options);
    }

    protected static function hasCertificate(string $listenerArn, array $certificate): bool
    {
        $listener = AwsResources::loadBalancerListenerOnPort(443);

        return collect($listener['Certificates'] ?? [])
            ->contains(fn (array $cert) => $cert['CertificateArn'] === $certificate['CertificateArn']);
    }
}
