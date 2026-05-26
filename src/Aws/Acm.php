<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Acm
{
    public static function certificate(string $domain): array
    {
        foreach (Aws::acm()->listCertificates()['CertificateSummaryList'] ?? [] as $certificate) {
            if ($certificate['DomainName'] === $domain) {
                return $certificate;
            }
        }

        throw new ResourceDoesNotExistException("Could not find ACM certificate for domain $domain");
    }
}
