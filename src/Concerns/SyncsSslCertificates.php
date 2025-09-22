<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\AwsResources;

trait SyncsSslCertificates
{
    protected function requestCertificate(string $apex): void
    {
        $certificate = Aws::acm()->requestCertificate([
            'DomainName' => $apex,
            'SubjectAlternativeNames' => ["*.{$apex}"],
            'ValidationMethod' => 'DNS',
        ]);

        $this->validateCertificate($certificate['CertificateArn'], $apex);
    }

    protected function validateCertificate(string $certificateArn, string $apex): void
    {
        do {
            $certificate = Aws::acm()->describeCertificate([
                'CertificateArn' => $certificateArn,
            ])['Certificate'];

            // take a little snooze because the AWS result
            // is incomplete on the first request
            sleep(2);
        } while (
            ! array_key_exists('DomainValidationOptions', $certificate) ||
            ! collect($certificate['DomainValidationOptions'])
                ->every(fn (array $option) => array_key_exists('ResourceRecord', $option))
        );

        Aws::route53()->changeResourceRecordSets([
            'ChangeBatch' => [
                'Changes' => collect($certificate['DomainValidationOptions'])
                    ->filter(fn (array $option) => $option['ValidationMethod'] === 'DNS'
                        && ! str_starts_with($option['ValidationDomain'], '*'))
                    ->map(function (array $option) {
                        return [
                            'Action' => 'UPSERT',
                            'ResourceRecordSet' => [
                                'Name' => $option['ResourceRecord']['Name'],
                                'Type' => $option['ResourceRecord']['Type'],
                                'ResourceRecords' => [
                                    [
                                        'Value' => $option['ResourceRecord']['Value'],
                                    ],
                                ],
                                'TTL' => 300,
                            ],
                        ];
                    })->toArray(),
                'Comment' => 'Created by yolo CLI',
            ],
            'HostedZoneId' => AwsResources::hostedZone($apex)['Id'],
        ]);

        // wait for the certificate to be issued
        $certificate = AwsResources::certificate($apex);

        if ($certificate['Status'] !== 'ISSUED') {
            do {
                $certificate = AwsResources::certificate($apex);

                // take a little snooze until the certificate is issued
                sleep(2);
            } while ($certificate['Status'] !== 'ISSUED');
        }
    }
}
