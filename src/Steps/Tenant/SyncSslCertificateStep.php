<?php

namespace Codinglabs\Yolo\Steps\Tenant;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\SyncsSslCertificates;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncSslCertificateStep extends TenantStep
{
    use SyncsSslCertificates;

    public function __invoke(array $options): StepResult
    {
        try {
            $certificate = AwsResources::certificate($this->config['apex']);

            if ($certificate['Status'] === 'PENDING_VALIDATION') {
                if (! Arr::get($options, 'dry-run')) {
                    $this->validateCertificate($certificate['CertificateArn'], $this->config['apex']);
                } else {
                    return StepResult::WOULD_SYNC;
                }
            }

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                $this->requestCertificate($this->config['apex']);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
