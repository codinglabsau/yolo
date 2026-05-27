<?php

namespace Codinglabs\Yolo\Steps\Sync\App\Solo;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\Acm\SslCertificate;

class SyncSslCertificateStep implements ExecutesWebStep
{
    public function __invoke(array $options): StepResult
    {
        $certificate = new SslCertificate(Manifest::apex());
        $summary = $certificate->find();

        if ($summary === null) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            $certificate->validate($certificate->request());

            return StepResult::CREATED;
        }

        if ($summary['Status'] === 'PENDING_VALIDATION') {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            $certificate->validate($summary['CertificateArn']);
        }

        return StepResult::SYNCED;
    }
}
