<?php

namespace Codinglabs\Yolo\Steps\Domain;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesDomainStep;
use Codinglabs\Yolo\Concerns\SyncsSslCertificates;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncSslCertificateStep implements ExecutesDomainStep
{
    use SyncsSslCertificates;

    public function __invoke(array $options): StepResult
    {
        try {
            $certificate = AwsResources::certificate(Manifest::apex());

            if ($certificate['Status'] === 'PENDING_VALIDATION') {
                if (! Arr::get($options, 'dry-run')) {
                    $this->validateCertificate($certificate['CertificateArn'], Manifest::apex());
                } else {
                    return StepResult::WOULD_SYNC;
                }
            }

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                $this->requestCertificate(Manifest::apex());

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
