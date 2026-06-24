<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Acm;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElbV2\HttpsListener;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncHttpsListenerStep implements ExecutesWebStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('domain')) {
            return StepResult::SKIPPED;
        }

        try {
            $certificate = Acm::certificate(Manifest::apex());
        } catch (ResourceDoesNotExistException) {
            return StepResult::SKIPPED;
        }

        if ($certificate['Status'] !== 'ISSUED') {
            return StepResult::SKIPPED;
        }

        $listener = new HttpsListener($certificate);

        // Cert-attachment is orchestration, not part of the resource's identity.
        // The app's SNI cert lives in the listener's certificate list
        // (DescribeListenerCertificates), not its single default cert — so checking
        // the default-only list read this as missing on every sync for any app that
        // wasn't the listener's creator. Record the change before the dry-run guard
        // so it shows in the plan and survives to apply.
        if ($listener->exists() && ! static::hasCertificate($listener->arn(), $certificate)) {
            $this->recordChange(Change::make('listener certificate', 'absent', 'attached'));

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
        try {
            ElbV2::listenerCertificate($listenerArn, $certificate['CertificateArn']);

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }
}
