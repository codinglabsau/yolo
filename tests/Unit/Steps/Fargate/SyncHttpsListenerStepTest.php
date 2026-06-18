<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncHttpsListenerStep;

const HTTPS_APEX = 'codinglabs.com.au';
const HTTPS_APP_CERT = 'arn:aws:acm:ap-southeast-2:111111111111:certificate/app-cert';
const HTTPS_DEFAULT_CERT = 'arn:aws:acm:ap-southeast-2:111111111111:certificate/default-cert';

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'apex' => HTTPS_APEX,
        'domain' => HTTPS_APEX,
    ]);

    bindIssuedAcmCertificate(HTTPS_APEX, HTTPS_APP_CERT);
});

it('is in sync when the cert is attached as an SNI cert, even though it is not the listener default', function (): void {
    // The acceptance criterion: the cert lives in the listener's SNI certificate
    // list (DescribeListenerCertificates), NOT its single default cert
    // (DescribeListeners). Checking only the default read this as missing on every
    // sync for any app that wasn't the listener's creator — the "always syncs" bug.
    $captured = [];
    bindRoutedElbV2Client(
        httpsListenerElbV2(attached: [HTTPS_DEFAULT_CERT, HTTPS_APP_CERT]),
        $captured,
    );

    $step = new SyncHttpsListenerStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::SYNCED);
    expect($step->changes())->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('AddListenerCertificates');
});

it('records drift on the plan pass and attaches the cert on apply', function (): void {
    $captured = [];
    bindRoutedElbV2Client(
        httpsListenerElbV2(attached: [HTTPS_DEFAULT_CERT]),
        $captured,
    );

    $plan = new SyncHttpsListenerStep();
    expect($plan(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($plan->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('AddListenerCertificates');

    $captured = [];
    bindRoutedElbV2Client(
        httpsListenerElbV2(attached: [HTTPS_DEFAULT_CERT]),
        $captured,
    );

    expect((new SyncHttpsListenerStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->toContain('AddListenerCertificates');
});

it('skips when no apex or domain is configured', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    $captured = [];
    bindRoutedElbV2Client(httpsListenerElbV2(attached: [HTTPS_APP_CERT]), $captured);

    expect((new SyncHttpsListenerStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
});

/**
 * A routed ELBv2 mock for the shared env :443 listener. The listener's default
 * cert is deliberately NOT the app cert, so a step that only inspects the default
 * (DescribeListeners) would always miss an attached SNI cert; the attached SNI
 * list (DescribeListenerCertificates) is driven by $attached.
 *
 * @param  array<int, string>  $attached  cert ARNs in the listener's SNI list
 * @return array<string, Result>
 */
function httpsListenerElbV2(array $attached): array
{
    return [
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
            ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc'],
        ]]),
        'DescribeListeners' => new Result(['Listeners' => [
            [
                'Port' => 443,
                'ListenerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:listener/app/yolo-testing/abc/443',
                'Certificates' => [['CertificateArn' => HTTPS_DEFAULT_CERT]],
            ],
        ]]),
        'DescribeListenerCertificates' => new Result(['Certificates' => array_map(
            fn (string $arn): array => ['CertificateArn' => $arn],
            $attached,
        )]),
        'DescribeTags' => new Result(['TagDescriptions' => [['Tags' => [
            ['Key' => 'Name', 'Value' => 'yolo-testing-https'],
            ['Key' => 'yolo:scope', 'Value' => 'env'],
            ['Key' => 'yolo:environment', 'Value' => 'testing'],
        ]]]]),
    ];
}
