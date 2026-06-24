<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncRedirectRuleStep;

/**
 * Bind the ALB + :443 listener lookups, plus a listener rule set the redirect step
 * will search by Name. Pass the rules/tags the DescribeRules/DescribeTags calls
 * return.
 *
 * @param  array<int, array<string, mixed>>  $rules
 * @param  array<int, array<string, mixed>>  $tagDescriptions
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindRedirectStepAws(array $rules, array $tagDescriptions, array &$captured): void
{
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:alb']]]),
        'DescribeListeners' => new Result(['Listeners' => [['Port' => 443, 'ListenerArn' => 'arn:listener']]]),
        'DescribeRules' => new Result(['Rules' => $rules]),
        'DescribeTags' => new Result(['TagDescriptions' => $tagDescriptions]),
    ], $captured);
}

it('skips a headless app with no domain', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect((new SyncRedirectRuleStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
});

it('reports the redirect as pending on the plan pass when the listener will be created this sync', function (): void {
    // Same first-sync prune trap as the forward rule: with the :443 listener not yet
    // created on the plan pass, a redirecting apex/www app must report pending (not a
    // self-pruning SKIPPED) when the listener will be created — an issued cert.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'example.com',
    ]);

    $captured = [];
    bindHostedZones();
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:alb']]]),
        'DescribeListeners' => new Result(['Listeners' => []]),
    ], $captured);
    bindIssuedAcmCertificate('example.com', 'arn:aws:acm:ap-southeast-2:111111111111:certificate/app-cert');

    $step = new SyncRedirectRuleStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('CreateRule');
});

it('still defers a bare subdomain on the plan pass when the listener is absent', function (): void {
    // A bare subdomain has no apex/www redirect, so even with the listener pending
    // there is nothing to plan — it must defer, not report a phantom rule.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'app.example.com',
    ]);

    $captured = [];
    bindHostedZones(['example.com']);
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:alb']]]),
        'DescribeListeners' => new Result(['Listeners' => []]),
    ], $captured);
    bindIssuedAcmCertificate('example.com', 'arn:aws:acm:ap-southeast-2:111111111111:certificate/app-cert');

    expect((new SyncRedirectRuleStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
});

it('skips a bare subdomain when there is no redirect rule to clean up', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'app.example.com',
    ]);

    $captured = [];
    bindHostedZones(['example.com']);
    bindRedirectStepAws(rules: [], tagDescriptions: [], captured: $captured);

    expect((new SyncRedirectRuleStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(collect($captured)->pluck('name')->all())->not->toContain('DeleteRule');
});

it('tears down its own redirect rule when the domain becomes a bare subdomain', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'app.example.com',
    ]);

    $captured = [];
    bindHostedZones(['example.com']);
    bindRedirectStepAws(
        rules: [[
            'RuleArn' => 'arn:rule:redirect',
            'Priority' => '2000',
            'Conditions' => [['Field' => 'host-header', 'HostHeaderConfig' => ['Values' => ['www.example.com']]]],
            'Actions' => [['Type' => 'redirect', 'RedirectConfig' => ['Host' => 'example.com']]],
        ]],
        tagDescriptions: [
            ['ResourceArn' => 'arn:rule:redirect', 'Tags' => [['Key' => 'Name', 'Value' => 'yolo-testing-my-app-redirect']]],
        ],
        captured: $captured,
    );

    expect((new SyncRedirectRuleStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(collect($captured)->where('name', 'DeleteRule')->first()['args']['RuleArn'])->toBe('arn:rule:redirect');
});

it('would-delete the orphaned redirect rule on a dry-run without deleting', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'app.example.com',
    ]);

    $captured = [];
    bindHostedZones(['example.com']);
    bindRedirectStepAws(
        rules: [[
            'RuleArn' => 'arn:rule:redirect',
            'Priority' => '2000',
            'Conditions' => [['Field' => 'host-header', 'HostHeaderConfig' => ['Values' => ['www.example.com']]]],
            'Actions' => [['Type' => 'redirect', 'RedirectConfig' => ['Host' => 'example.com']]],
        ]],
        tagDescriptions: [
            ['ResourceArn' => 'arn:rule:redirect', 'Tags' => [['Key' => 'Name', 'Value' => 'yolo-testing-my-app-redirect']]],
        ],
        captured: $captured,
    );

    expect((new SyncRedirectRuleStep())(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE)
        ->and(collect($captured)->pluck('name')->all())->not->toContain('DeleteRule');
});
