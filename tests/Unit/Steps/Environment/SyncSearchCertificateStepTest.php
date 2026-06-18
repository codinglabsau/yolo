<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncSearchCertificateStep;

const SEARCH_DOMAIN = 'example.com.au';
const SEARCH_CERT = 'arn:aws:acm:ap-southeast-2:111111111111:certificate/search-cert';

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

/**
 * Typesense offered in the env manifest (with a domain) AND claimed by a live
 * app -> the service is in Provision, and the search host is search.{domain}.
 * The cert is already issued (the env apex/wildcard cert an app may share).
 */
function bindSearchCertWorld(array &$captured): void
{
    bindServiceLifecycleWorld([
        'manifest' => 'domain: ' . SEARCH_DOMAIN . "\nservices:\n  typesense:\n    version: \"29.0\"\n    cpu: 256\n    memory: 1024\n",
        'claims' => ['my-app' => ['typesense']],
        'clusters' => ['my-app' => true],
    ], $captured);

    bindIssuedAcmCertificate(SEARCH_DOMAIN, SEARCH_CERT);
}

it('bootstraps the shared :443 listener from the search cert when no app has', function (): void {
    $captured = [];
    bindSearchCertWorld($captured);

    // The ALB is up but carries no :443 listener yet.
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
            ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc'],
        ]]),
        'DescribeListeners' => new Result(['Listeners' => [
            ['Port' => 80, 'ListenerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:listener/app/yolo-testing/abc/80'],
        ]]),
        'CreateListener' => new Result(['Listeners' => [['ListenerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:listener/app/yolo-testing/abc/443']]]),
    ], $elb);

    expect((new SyncSearchCertificateStep())([]))->toBe(StepResult::CREATED);

    $create = collect($elb)->firstWhere('name', 'CreateListener');
    expect($create)->not->toBeNull();
    expect($create['args']['Port'])->toBe(443);
    expect($create['args']['Certificates'][0]['CertificateArn'])->toBe(SEARCH_CERT);
});

it('plans the :443 listener bootstrap without creating it', function (): void {
    $captured = [];
    bindSearchCertWorld($captured);

    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
            ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:lb'],
        ]]),
        'DescribeListeners' => new Result(['Listeners' => [['Port' => 80, 'ListenerArn' => 'arn:80']]]),
    ], $elb);

    $step = new SyncSearchCertificateStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect($step->changes())->not->toBeEmpty();
    expect(array_column($elb, 'name'))->not->toContain('CreateListener');
});

it('defers when the shared ALB is not provisioned yet', function (): void {
    $captured = [];
    bindSearchCertWorld($captured);

    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => []]),
    ], $elb);

    $step = new SyncSearchCertificateStep();

    expect($step([]))->toBe(StepResult::SKIPPED);
    expect($step->changes())->not->toBeEmpty();
    expect(array_column($elb, 'name'))->not->toContain('CreateListener');
});

it('is in sync when the cert already rides an existing :443 listener', function (): void {
    $captured = [];
    bindSearchCertWorld($captured);

    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
            ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:lb'],
        ]]),
        'DescribeListeners' => new Result(['Listeners' => [['Port' => 443, 'ListenerArn' => 'arn:443']]]),
        'DescribeListenerCertificates' => new Result(['Certificates' => [['CertificateArn' => SEARCH_CERT]]]),
    ], $elb);

    expect((new SyncSearchCertificateStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($elb, 'name'))
        ->not->toContain('CreateListener')
        ->not->toContain('AddListenerCertificates');
});

it('SNI-attaches the search cert when an app already bootstrapped :443', function (): void {
    $captured = [];
    bindSearchCertWorld($captured);

    // :443 exists (an app brought it up) but the search cert isn't on it yet.
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [
            ['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:lb'],
        ]]),
        'DescribeListeners' => new Result(['Listeners' => [['Port' => 443, 'ListenerArn' => 'arn:443']]]),
        'DescribeListenerCertificates' => new Result(['Certificates' => [['CertificateArn' => 'arn:some-app-cert']]]),
        'AddListenerCertificates' => new Result([]),
    ], $elb);

    expect((new SyncSearchCertificateStep())([]))->toBe(StepResult::SYNCED);

    $attach = collect($elb)->firstWhere('name', 'AddListenerCertificates');
    expect($attach)->not->toBeNull();
    expect($attach['args']['Certificates'][0]['CertificateArn'])->toBe(SEARCH_CERT);
    expect(array_column($elb, 'name'))->not->toContain('CreateListener');
});

it('skips entirely when typesense is not provisioned', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services: {  }\n", // offer removed -> not provisioned
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    expect((new SyncSearchCertificateStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
});
