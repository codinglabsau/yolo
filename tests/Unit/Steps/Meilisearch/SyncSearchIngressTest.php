<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncSearchRuleStep;
use Codinglabs\Yolo\Resources\ElbV2\SearchListenerRule;
use Codinglabs\Yolo\Steps\Sync\App\SyncSearchRecordSetStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => []],
        'scout' => ['driver' => 'meilisearch'],
    ]);
});

describe('search listener rule', function (): void {
    it('derives the capability-named host from the apex', function (): void {
        expect((new SearchListenerRule('arn:listener'))->hosts())->toBe(['search.codinglabs.com.au'])
            ->and((new SearchListenerRule('arn:listener'))->name())->toBe('yolo-testing-my-app-search');
    });

    it('derives search from the apex on a www-canonical app so the wildcard cert always covers it', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'apex' => 'codinglabs.com.au', 'domain' => 'www.codinglabs.com.au',
            'tasks' => ['web' => []],
            'scout' => ['driver' => 'meilisearch'],
        ]);

        // The app cert is {apex} + *.{apex} — search.www.{apex} would sit two
        // levels under the wildcard and serve an invalid certificate.
        expect((new SearchListenerRule('arn:listener'))->hosts())->toBe(['search.codinglabs.com.au']);
    });

    it('defers when there is no HTTPS listener yet', function (): void {
        $captured = [];
        bindRoutedElbV2Client([
            'DescribeLoadBalancers' => new Result(['LoadBalancers' => []]),
        ], $captured);

        expect((new SyncSearchRuleStep())([]))->toBe(StepResult::SKIPPED);
    });

    it('creates the rule forwarding search.{apex} to the shared meilisearch target group', function (): void {
        $captured = [];
        bindRoutedElbV2Client([
            'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
                ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:alb', 'DNSName' => 'alb.aws', 'CanonicalHostedZoneId' => 'Z-ALB'],
            ]]),
            'DescribeListeners' => new Result(['Listeners' => [
                ['ListenerArn' => 'arn:listener:443', 'Port' => 443],
            ]]),
            'DescribeRules' => new Result(['Rules' => []]),
            'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:tg:meili']]]),
            'CreateRule' => new Result(),
        ], $captured);

        expect((new SyncSearchRuleStep())([]))->toBe(StepResult::CREATED);

        $create = collect($captured)->firstWhere('name', 'CreateRule');

        expect($create['args']['Conditions'][0]['HostHeaderConfig']['Values'])->toBe(['search.codinglabs.com.au'])
            ->and($create['args']['Actions'][0])->toBe(['Type' => 'forward', 'TargetGroupArn' => 'arn:tg:meili'])
            ->and(collect($create['args']['Tags'])->firstWhere('Key', 'Name')['Value'])->toBe('yolo-testing-my-app-search');
    });
});

describe('search record set', function (): void {
    it('creates the search alias record pointing at the env ALB', function (): void {
        $elb = [];
        bindRoutedElbV2Client([
            'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
                ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:alb', 'DNSName' => 'alb.aws', 'CanonicalHostedZoneId' => 'Z-ALB'],
            ]]),
        ], $elb);

        $captured = [];
        bindRoutedRoute53Client([
            'ListHostedZones' => new Result(['HostedZones' => [
                ['Id' => '/hostedzone/Z123', 'Name' => 'codinglabs.com.au.'],
            ]]),
            'ListResourceRecordSets' => new Result(['ResourceRecordSets' => []]),
            'ChangeResourceRecordSets' => new Result(),
        ], $captured);

        expect((new SyncSearchRecordSetStep())([]))->toBe(StepResult::CREATED);

        $change = collect($captured)->firstWhere('name', 'ChangeResourceRecordSets')['args'];
        $record = $change['ChangeBatch']['Changes'][0]['ResourceRecordSet'];

        // The SDK's CleanIdMiddleware strips the /hostedzone/ prefix on the wire.
        expect($change['HostedZoneId'])->toBe('Z123')
            ->and($record['Name'])->toBe('search.codinglabs.com.au')
            ->and($record['Type'])->toBe('A')
            ->and($record['AliasTarget']['DNSName'])->toBe('alb.aws');
    });

    it('reports in sync without writing when the record already aliases the ALB', function (): void {
        $elb = [];
        bindRoutedElbV2Client([
            'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
                ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:alb', 'DNSName' => 'alb.aws', 'CanonicalHostedZoneId' => 'Z-ALB'],
            ]]),
        ], $elb);

        $captured = [];
        bindRoutedRoute53Client([
            'ListHostedZones' => new Result(['HostedZones' => [
                ['Id' => '/hostedzone/Z123', 'Name' => 'codinglabs.com.au.'],
            ]]),
            'ListResourceRecordSets' => new Result(['ResourceRecordSets' => [
                [
                    'Name' => 'search.codinglabs.com.au.',
                    'Type' => 'A',
                    'AliasTarget' => ['DNSName' => 'alb.aws.', 'HostedZoneId' => 'Z-ALB'],
                ],
            ]]),
        ], $captured);

        expect((new SyncSearchRecordSetStep())([]))->toBe(StepResult::SYNCED);
        expect(array_column($captured, 'name'))->not->toContain('ChangeResourceRecordSets');
    });

    it('reports the record pending on a greenfield dry-run without writing', function (): void {
        $elb = [];
        bindRoutedElbV2Client([
            'DescribeLoadBalancers' => new Result(['LoadBalancers' => []]),
        ], $elb);

        $captured = [];
        bindRoutedRoute53Client([
            'ListHostedZones' => new Result(['HostedZones' => []]),
        ], $captured);

        $step = new SyncSearchRecordSetStep();

        expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
        expect($step->changes())->not->toBeEmpty();
        expect(array_column($captured, 'name'))->not->toContain('ChangeResourceRecordSets');
    });

    it('does not write the record during a dry-run when it is missing', function (): void {
        $elb = [];
        bindRoutedElbV2Client([
            'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
                ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:alb', 'DNSName' => 'alb.aws', 'CanonicalHostedZoneId' => 'Z-ALB'],
            ]]),
        ], $elb);

        $captured = [];
        bindRoutedRoute53Client([
            'ListHostedZones' => new Result(['HostedZones' => [
                ['Id' => '/hostedzone/Z123', 'Name' => 'codinglabs.com.au.'],
            ]]),
            'ListResourceRecordSets' => new Result(['ResourceRecordSets' => []]),
        ], $captured);

        expect((new SyncSearchRecordSetStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
        expect(array_column($captured, 'name'))->not->toContain('ChangeResourceRecordSets');
    });
});
