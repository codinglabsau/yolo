<?php

namespace Codinglabs\Yolo\Steps\Sync\App\Solo;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\Acm\SslCertificate;

class SyncSslCertificateStep implements ExecutesWebStep
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $certificate = new SslCertificate(Manifest::certificateDomain(), Manifest::apex(), Manifest::additionalDomains());
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

            return StepResult::SYNCED;
        }

        // ACM certificates are immutable — an already-ISSUED certificate whose
        // additional-SAN list has grown (a landlord adding another domain to an
        // already-provisioned certificate) can't be amended in place, only
        // superseded by requesting a fresh certificate covering the full desired
        // SAN set. The old certificate is left standing, same as every other
        // domain-name change here: this class is deliberately NOT Deletable.
        if ($certificate->isMissingAdditionalSans($summary)) {
            $this->recordChange(Change::make('certificate SANs', implode(', ', $summary['SubjectAlternativeNameSummaries'] ?? []), implode(', ', Manifest::additionalDomains())));

            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            $certificate->validate($certificate->request());
        }

        return StepResult::SYNCED;
    }
}
