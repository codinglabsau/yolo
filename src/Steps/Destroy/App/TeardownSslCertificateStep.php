<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Concerns\RecordsWarnings;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\Acm\SslCertificate;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Tears down this app's ACM certificate. ACM refuses to delete a certificate
 * still attached to a listener, so the cert is first detached from the shared
 * :443 listener's SNI set (an SNI cert removes cleanly; the listener's single
 * default cert cannot be removed this way — tolerated). If the cert is still in
 * use after that (it was the listener's default), the delete no-ops and the cert
 * is left for `yolo destroy:environment` to free when it removes the listener.
 */
class TeardownSslCertificateStep implements ExecutesWebStep
{
    use RecordsChanges;
    use RecordsWarnings;

    public function __invoke(array $options): StepResult
    {
        $certificate = new SslCertificate(Manifest::apex());
        $summary = $certificate->find();

        if ($summary === null) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make('SSL certificate', 'provisioned', null));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $this->detachFromListener($summary['CertificateArn']);

        try {
            $certificate->delete();
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceInUseException') {
                $this->recordWarning('The SSL certificate is still the shared HTTPS listener\'s default — left in place; `yolo destroy:environment` frees it with the listener.');

                return StepResult::SKIPPED;
            }

            throw $e;
        }

        return StepResult::DELETED;
    }

    /**
     * Remove the certificate from the shared :443 listener's SNI set. The
     * listener's default certificate can't be removed this way (AWS rejects it)
     * and a not-attached cert is a no-op — both are tolerated, since the delete
     * that follows degrades safely if the cert is still referenced.
     */
    protected function detachFromListener(string $certificateArn): void
    {
        try {
            $listener = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);
        } catch (ResourceDoesNotExistException) {
            return;
        }

        try {
            Aws::elasticLoadBalancingV2()->removeListenerCertificates([
                'ListenerArn' => $listener['ListenerArn'],
                'Certificates' => [['CertificateArn' => $certificateArn]],
            ]);
        } catch (AwsException) {
            // Default cert or already detached — leave it; delete() handles InUse.
        }
    }
}
