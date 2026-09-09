<?php

use Aws\Result;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\QueueBacklogPolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
    ]);
});

it('tracks backlog-per-task with metric math dividing visible messages by running tasks', function (): void {
    $config = (new QueueBacklogPolicy())->configuration();

    expect($config['TargetValue'])->toBe(100.0);

    $metrics = collect($config['CustomizedMetricSpecification']['Metrics']);

    // The visible-messages metric on this app's queue, the running-task count, and
    // the math expression that divides them — only the expression returns data.
    expect($metrics->firstWhere('Id', 'visible')['MetricStat']['Metric'])->toMatchArray([
        'Namespace' => 'AWS/SQS',
        'MetricName' => 'ApproximateNumberOfMessagesVisible',
    ]);
    expect($metrics->firstWhere('Id', 'running')['MetricStat']['Metric']['MetricName'])->toBe('RunningTaskCount');
    expect($metrics->firstWhere('Id', 'backlog_per_task'))->toMatchArray([
        'Expression' => 'visible / running',
        'ReturnData' => true,
    ]);
});

it('reads the backlog-per-task target from the manifest', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['autoscaling' => ['backlog-per-task' => 40]]],
    ]);

    expect((new QueueBacklogPolicy())->configuration()['TargetValue'])->toBe(40.0);
});

it('upserts the target-tracking policy onto the queue scalable target when absent', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:aws:autoscaling:...:policy/queue']),
    ], $captured);

    $changes = (new QueueBacklogPolicy())->synchronise(apply: true);

    expect($changes)->not->toBe([]);

    $put = collect($captured)->firstWhere('name', 'PutScalingPolicy');
    expect($put['args'])->toMatchArray([
        'PolicyType' => 'TargetTrackingScaling',
        'ResourceId' => 'service/yolo-testing-my-app/yolo-testing-my-app-queue',
    ]);
});

it('reports drift without writing on a dry-run', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
    ], $captured);

    $changes = (new QueueBacklogPolicy())->synchronise(apply: false);

    expect($changes)->not->toBe([]);
    expect(collect($captured)->pluck('name'))->not->toContain('PutScalingPolicy');
});

it('sums every tenant queue and the landlord queue under dedicated isolation', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
        'multitenancy' => ['queue-isolation' => 'dedicated', 'tenants' => ['acme' => [], 'globex-corp' => []]],
    ]);

    $metrics = collect((new QueueBacklogPolicy())->configuration()['CustomizedMetricSpecification']['Metrics']);

    // No queue exists at the app name under dedicated isolation — the tier drains
    // the landlord queue plus one per tenant, so each is its own metric term and the
    // expression sums them before dividing by running tasks.
    $queueNames = $metrics
        ->filter(fn (array $metric): bool => ($metric['MetricStat']['Metric']['Namespace'] ?? null) === 'AWS/SQS')
        ->mapWithKeys(fn (array $metric): array => [$metric['Id'] => $metric['MetricStat']['Metric']['Dimensions'][0]['Value']]);

    expect($queueNames->all())->toBe([
        'visible_landlord' => 'yolo-testing-my-app-landlord',
        'visible_acme' => 'yolo-testing-my-app-acme',
        'visible_globex_corp' => 'yolo-testing-my-app-globex-corp',
    ]);
    expect($metrics->firstWhere('Id', 'backlog_per_task')['Expression'])
        ->toBe('SUM([visible_landlord, visible_acme, visible_globex_corp]) / running');
});

it('keeps the single default queue under shared isolation', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
        'multitenancy' => ['queue-isolation' => 'shared', 'tenants' => ['acme' => [], 'globex' => []]],
    ]);

    $metrics = collect((new QueueBacklogPolicy())->configuration()['CustomizedMetricSpecification']['Metrics']);

    expect($metrics->firstWhere('Id', 'visible')['MetricStat']['Metric']['Dimensions'])
        ->toBe([['Name' => 'QueueName', 'Value' => 'yolo-testing-my-app']]);
    expect($metrics->pluck('Id')->all())->toBe(['visible', 'running', 'backlog_per_task']);
    expect($metrics->firstWhere('Id', 'backlog_per_task')['Expression'])->toBe('visible / running');
});

it('reports no drift when the live policy already watches the manifest queue set', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
        'multitenancy' => ['queue-isolation' => 'dedicated', 'tenants' => ['acme' => []]],
    ]);

    $policy = new QueueBacklogPolicy();

    // Rerun after apply: what PutScalingPolicy wrote is what DescribeScalingPolicies reads back.
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [[
            'PolicyName' => $policy->policyName(),
            'TargetTrackingScalingPolicyConfiguration' => $policy->configuration(),
        ]]]),
    ], $captured);

    expect($policy->synchronise(apply: true))->toBe([]);
    expect(collect($captured)->pluck('name'))->not->toContain('PutScalingPolicy');
});

it('re-puts the policy when a tenant is added so the metric list follows the manifest', function (): void {
    $manifest = fn (array $tenants): array => [
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
        'multitenancy' => ['queue-isolation' => 'dedicated', 'tenants' => $tenants],
    ];

    writeManifest($manifest(['acme' => []]));
    $live = (new QueueBacklogPolicy())->configuration();

    writeManifest($manifest(['acme' => [], 'initech' => []]));
    $policy = new QueueBacklogPolicy();

    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [[
            'PolicyName' => $policy->policyName(),
            'TargetTrackingScalingPolicyConfiguration' => $live,
        ]]]),
        'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:aws:autoscaling:...:policy/queue']),
    ], $captured);

    $changes = $policy->synchronise(apply: true);

    expect(collect($changes)->pluck('attribute')->all())->toBe(['queue backlog queues']);
    expect($changes[0]->from)->toBe('yolo-testing-my-app-acme, yolo-testing-my-app-landlord');
    expect($changes[0]->to)->toBe('yolo-testing-my-app-acme, yolo-testing-my-app-initech, yolo-testing-my-app-landlord');

    $put = collect($captured)->firstWhere('name', 'PutScalingPolicy');
    expect(collect($put['args']['TargetTrackingScalingPolicyConfiguration']['CustomizedMetricSpecification']['Metrics'])->pluck('Id')->all())
        ->toBe(['visible_landlord', 'visible_acme', 'visible_initech', 'running', 'backlog_per_task']);
});

it('replaces the single-queue policy shape when isolation switches to dedicated', function (): void {
    $manifest = fn (array $multitenancy): array => [
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
        'multitenancy' => $multitenancy,
    ];

    writeManifest($manifest(['queue-isolation' => 'shared', 'tenants' => ['acme' => []]]));
    $live = (new QueueBacklogPolicy())->configuration();

    writeManifest($manifest(['queue-isolation' => 'dedicated', 'tenants' => ['acme' => []]]));
    $policy = new QueueBacklogPolicy();

    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [[
            'PolicyName' => $policy->policyName(),
            'TargetTrackingScalingPolicyConfiguration' => $live,
        ]]]),
        'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:aws:autoscaling:...:policy/queue']),
    ], $captured);

    $changes = $policy->synchronise(apply: true);

    expect($changes)->toHaveCount(1);
    expect($changes[0]->from)->toBe('yolo-testing-my-app');

    // Same policy name, so the put overwrites the old shape in place rather than
    // leaving a second policy behind.
    $put = collect($captured)->firstWhere('name', 'PutScalingPolicy');
    expect($put['args']['PolicyName'])->toBe($policy->policyName());
    expect(collect($put['args']['TargetTrackingScalingPolicyConfiguration']['CustomizedMetricSpecification']['Metrics'])->pluck('Id'))
        ->not->toContain('visible');
});
