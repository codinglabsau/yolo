<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncHttpsListenerStep implements ExecutesWebStep
{
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

        try {
            $listener = AwsResources::loadBalancerListenerOnPort(443);

            $hasCertificate = collect($listener['Certificates'] ?? [])
                ->contains(fn (array $cert) => $cert['CertificateArn'] === $certificate['CertificateArn']);

            if (! $hasCertificate) {
                if (Arr::get($options, 'dry-run')) {
                    return StepResult::WOULD_SYNC;
                }

                Aws::elasticLoadBalancingV2()->addListenerCertificates([
                    'ListenerArn' => $listener['ListenerArn'],
                    'Certificates' => [
                        ['CertificateArn' => $certificate['CertificateArn']],
                    ],
                ]);
            }

            $this->reconcileTags($listener['ListenerArn'], Arr::get($options, 'dry-run'));

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            Aws::elasticLoadBalancingV2()->createListener([
                'LoadBalancerArn' => AwsResources::loadBalancer()['LoadBalancerArn'],
                'Protocol' => 'HTTPS',
                'Port' => 443,
                'SslPolicy' => 'ELBSecurityPolicy-TLS13-1-2-2021-06',
                'Certificates' => [
                    ['CertificateArn' => $certificate['CertificateArn']],
                ],
                'DefaultActions' => [
                    [
                        'Type' => 'fixed-response',
                        'FixedResponseConfig' => [
                            'StatusCode' => '503',
                            'ContentType' => 'text/plain',
                            'MessageBody' => 'No application matched the host header.',
                        ],
                    ],
                ],
                ...Aws::tags(['Name' => static::name()]),
            ]);

            return StepResult::CREATED;
        }
    }

    protected static function name(): string
    {
        return Helpers::keyedResourceName('https', exclusive: false);
    }

    protected function reconcileTags(string $arn, bool $dryRun): void
    {
        $current = Aws::flattenTags(
            Aws::elasticLoadBalancingV2()->describeTags(['ResourceArns' => [$arn]])['TagDescriptions'][0]['Tags'] ?? []
        );

        $missing = Aws::tagsRequiringSync(
            Aws::expectedTags(['Name' => static::name()]),
            $current,
        );

        if (empty($missing) || $dryRun) {
            return;
        }

        Aws::elasticLoadBalancingV2()->addTags([
            'ResourceArns' => [$arn],
            'Tags' => collect($missing)
                ->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])
                ->values()
                ->all(),
        ]);
    }
}
