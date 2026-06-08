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

it('skips a headless app with no domain or apex', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect((new SyncRedirectRuleStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
});

it('skips a bare subdomain when there is no redirect rule to clean up', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'apex' => 'codinglabs.com.au', 'domain' => 'app.codinglabs.com.au',
    ]);

    $captured = [];
    bindRedirectStepAws(rules: [], tagDescriptions: [], captured: $captured);

    expect((new SyncRedirectRuleStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(collect($captured)->pluck('name')->all())->not->toContain('DeleteRule');
});

it('tears down its own redirect rule when the domain becomes a bare subdomain', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'apex' => 'codinglabs.com.au', 'domain' => 'app.codinglabs.com.au',
    ]);

    $captured = [];
    bindRedirectStepAws(
        rules: [[
            'RuleArn' => 'arn:rule:redirect',
            'Priority' => '2000',
            'Conditions' => [['Field' => 'host-header', 'HostHeaderConfig' => ['Values' => ['www.codinglabs.com.au']]]],
            'Actions' => [['Type' => 'redirect', 'RedirectConfig' => ['Host' => 'codinglabs.com.au']]],
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
        'apex' => 'codinglabs.com.au', 'domain' => 'app.codinglabs.com.au',
    ]);

    $captured = [];
    bindRedirectStepAws(
        rules: [[
            'RuleArn' => 'arn:rule:redirect',
            'Priority' => '2000',
            'Conditions' => [['Field' => 'host-header', 'HostHeaderConfig' => ['Values' => ['www.codinglabs.com.au']]]],
            'Actions' => [['Type' => 'redirect', 'RedirectConfig' => ['Host' => 'codinglabs.com.au']]],
        ]],
        tagDescriptions: [
            ['ResourceArn' => 'arn:rule:redirect', 'Tags' => [['Key' => 'Name', 'Value' => 'yolo-testing-my-app-redirect']]],
        ],
        captured: $captured,
    );

    expect((new SyncRedirectRuleStep())(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE)
        ->and(collect($captured)->pluck('name')->all())->not->toContain('DeleteRule');
});
