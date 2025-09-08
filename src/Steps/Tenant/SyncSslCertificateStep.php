<?php

namespace Codinglabs\Yolo\Steps\Tenant;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncSslCertificateStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        try {
            $certificate = AwsResources::certificate($this->config['apex']);

            if ($certificate['Status'] === 'PENDING_VALIDATION') {
                if (! Arr::get($options, 'dry-run')) {
                    $this->validateCertificate($certificate['CertificateArn']);
                } else {
                    return StepResult::WOULD_SYNC;
                }
            }

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                $certificate = Aws::acm()->requestCertificate([
                    'DomainName' => $this->config['apex'],
                    'SubjectAlternativeNames' => ["*.{$this->config['apex']}"],
                    'ValidationMethod' => 'DNS',
                ]);

                $this->validateCertificate($certificate['CertificateArn']);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }

    protected function validateCertificate(string $certificateArn): void
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
                'Changes' => collect($certificate['DomainValidationOptions'])->map(function (array $option) {
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
            'HostedZoneId' => AwsResources::hostedZone($this->config['apex'])['Id'],
        ]);
    }
}
