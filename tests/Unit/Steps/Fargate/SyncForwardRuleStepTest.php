<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncForwardRuleStep;

const FORWARD_APEX = 'example.com';
const FORWARD_CERT = 'arn:aws:acm:ap-southeast-2:111111111111:certificate/app-cert';

/**
 * Bind the ALB lookup with NO :443 listener present, so the forward-rule step's
 * listenerOnPort(443) misses — the exact shape of a fresh env's plan pass, where
 * the bootstrapping HTTPS listener hasn't been created yet.
 *
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindForwardStepWithoutListener(array &$captured): void
{
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
            ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:alb'],
        ]]),
        'DescribeListeners' => new Result(['Listeners' => []]),
    ], $captured);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => FORWARD_APEX,
    ]);
    bindHostedZones();
});

it('skips a headless app with no domain', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect((new SyncForwardRuleStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
});

it('reports the rule as pending on the plan pass when the listener will be created this sync', function (): void {
    // The bug this guards: on a fresh env's plan pass the :443 listener doesn't
    // exist yet (it's bootstrapped later in the same apply), so a bare SKIPPED here
    // is pruned from the apply pass and the target group is never wired to the
    // listener — ECS CreateService then rejects the web service. An issued cert
    // means the listener WILL be created, so the step must report pending instead.
    $captured = [];
    bindForwardStepWithoutListener($captured);
    bindIssuedAcmCertificate(FORWARD_APEX, FORWARD_CERT);

    $step = new SyncForwardRuleStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->not->toBeEmpty();
    // It plans pending without trying to create the rule against a missing listener.
    expect(array_column($captured, 'name'))->not->toContain('CreateRule');
});

it('reports the rule as pending on a greenfield plan pass, before the certificate is even requested', function (): void {
    // The greenfield shape of the same prune trap: on a first-ever sync there is no
    // certificate at plan time either — it's requested AND validated to ISSUED
    // earlier in the same apply — so gating on "issued right now" reads this as a
    // defer, prunes the step, and leaves the target group unattached.
    $captured = [];
    bindForwardStepWithoutListener($captured);
    bindNoAcmCertificates();

    $step = new SyncForwardRuleStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('CreateRule');
});

it('reports the rule as pending on the plan pass while the certificate is still validating', function (): void {
    // PENDING_VALIDATION resolves within this apply too — the certificate step blocks
    // until ACM reports it issued.
    $captured = [];
    bindForwardStepWithoutListener($captured);
    bindIssuedAcmCertificate(FORWARD_APEX, FORWARD_CERT, 'PENDING_VALIDATION');

    expect((new SyncForwardRuleStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
});

it('defers on the plan pass when the certificate cannot be issued this run', function (): void {
    // A terminal status the certificate step passes through untouched: nothing this
    // run makes it issuable, so no listener is created and the rule genuinely defers.
    $captured = [];
    bindForwardStepWithoutListener($captured);
    bindIssuedAcmCertificate(FORWARD_APEX, FORWARD_CERT, 'VALIDATION_TIMED_OUT');

    $step = new SyncForwardRuleStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::SKIPPED);
    expect($step->changes())->toBeEmpty();
});

it('defers on the apply pass when the listener genuinely is not present', function (): void {
    // Apply never fabricates a rule against a missing listener — even with an issued
    // cert, an absent listener on apply means the cold-cert path, so it defers.
    $captured = [];
    bindForwardStepWithoutListener($captured);
    bindIssuedAcmCertificate(FORWARD_APEX, FORWARD_CERT);

    expect((new SyncForwardRuleStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED);
    expect(array_column($captured, 'name'))->not->toContain('CreateRule');
});
