<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Steps\Sync\App\Tenant\AttachSslCertificateToLoadBalancerListenerStep;

const TENANT_APEX = 'tenant-one.com';
const TENANT_CERT = 'arn:aws:acm:ap-southeast-2:111111111111:certificate/tenant-cert';
const TENANT_DEFAULT_CERT = 'arn:aws:acm:ap-southeast-2:111111111111:certificate/default-cert';

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['tenants' => ['tenant-one' => ['domain' => TENANT_APEX]]],
    ]);

    // No zones in the account, so the tenant's apex derives to its own domain.
    bindHostedZones();
});

it('honours the reconciler contract for the tenant SNI attachment', function (): void {
    bindIssuedAcmCertificate(TENANT_APEX, TENANT_CERT);

    assertSyncStepReconciles(
        makeStep: fn (): TenantStep => tenantAttachStep(),
        bindInSync: function (array &$captured): void {
            bindRoutedElbV2Client(tenantListenerElbV2(attached: [TENANT_DEFAULT_CERT, TENANT_CERT]), $captured);
        },
        bindDrifted: function (array &$captured): void {
            bindRoutedElbV2Client(tenantListenerElbV2(attached: [TENANT_DEFAULT_CERT]), $captured);
        },
        writeCommand: 'AddListenerCertificates',
    );
});

it('plans the attachment as pending when the tenant certificate is still validating', function (): void {
    // Greenfield: SyncSslCertificateStep requests and validates the certificate
    // earlier in the same apply, so on the plan pass it is not issued yet. The step
    // must survive to apply rather than skip (and be pruned) or throw.
    bindIssuedAcmCertificate(TENANT_APEX, TENANT_CERT, status: 'PENDING_VALIDATION');

    $captured = [];
    bindRoutedElbV2Client(tenantListenerElbV2(attached: [TENANT_DEFAULT_CERT]), $captured);

    $step = tenantAttachStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('AddListenerCertificates');
});

it('plans the attachment as pending when the certificate does not exist yet', function (): void {
    bindNoAcmCertificates();

    $captured = [];
    bindRoutedElbV2Client(tenantListenerElbV2(attached: [TENANT_DEFAULT_CERT]), $captured);

    $step = tenantAttachStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('AddListenerCertificates');
});

it('plans the attachment as pending when the :443 listener does not exist yet', function (): void {
    bindIssuedAcmCertificate(TENANT_APEX, TENANT_CERT);

    $captured = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
            ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc'],
        ]]),
        'DescribeListeners' => new Result(['Listeners' => []]),
    ], $captured);

    $step = tenantAttachStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('AddListenerCertificates');
});

function tenantAttachStep(): TenantStep
{
    return (new AttachSslCertificateToLoadBalancerListenerStep())
        ->setTenantId('tenant-one')
        ->setConfig(Manifest::tenants()['tenant-one']);
}

/**
 * A routed ELBv2 mock for the shared env `:443` listener. Its default certificate
 * is deliberately not the tenant's, so a step inspecting only the default
 * (DescribeListeners) would read an attached SNI certificate as missing; the SNI
 * list (DescribeListenerCertificates) is driven by $attached.
 *
 * @param  array<int, string>  $attached  cert ARNs in the listener's SNI list
 * @return array<string, Result>
 */
function tenantListenerElbV2(array $attached): array
{
    return [
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
            ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc'],
        ]]),
        'DescribeListeners' => new Result(['Listeners' => [
            [
                'Port' => 443,
                'ListenerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:listener/app/yolo-testing/abc/443',
                'Certificates' => [['CertificateArn' => TENANT_DEFAULT_CERT]],
            ],
        ]]),
        'DescribeListenerCertificates' => new Result(['Certificates' => array_map(
            fn (string $arn): array => ['CertificateArn' => $arn],
            $attached,
        )]),
    ];
}
