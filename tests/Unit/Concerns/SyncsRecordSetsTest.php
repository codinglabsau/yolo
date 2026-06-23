<?php

declare(strict_types=1);

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use Aws\Route53\Route53Client;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Concerns\SyncsRecordSets;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;

function recordSetSyncer(): object
{
    return new class()
    {
        use SyncsRecordSets;
    };
}

/**
 * Bind a mock Route 53 client: ListHostedZones returns the supplied zones, and
 * every other command (ChangeResourceRecordSets) resolves an empty Result. All
 * calls are captured so the change batch can be asserted.
 *
 * @param  array<int, array<string, mixed>>  $hostedZones
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindMockRoute53Client(array $hostedZones, array &$captured): void
{
    $mock = new class($hostedZones, $captured) extends MockHandler
    {
        /**
         * @param  array<int, array<string, mixed>>  $hostedZones
         * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
         */
        public function __construct(protected array $hostedZones, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            return Create::promiseFor(match ($cmd->getName()) {
                'ListHostedZones' => new Result(['HostedZones' => $this->hostedZones]),
                default => new Result(),
            });
        }
    };

    Helpers::app()->instance('route53', new Route53Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindAlbLookup(array &$captured): void
{
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [[
            'LoadBalancerName' => (new LoadBalancer())->name(),
            'DNSName' => 'alb-1.ap-southeast-2.elb.amazonaws.com',
            'CanonicalHostedZoneId' => 'ZALB123',
        ]]]),
    ], $captured);
}

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('upserts both the apex and www alias records for an apex domain', function (): void {
    $elb = [];
    bindAlbLookup($elb);

    $r53 = [];
    bindMockRoute53Client([['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE1']], $r53);

    recordSetSyncer()->syncRecordSet('example.com', 'example.com');

    $change = collect($r53)->firstWhere('name', 'ChangeResourceRecordSets');
    $changes = $change['args']['ChangeBatch']['Changes'];

    expect($changes)->toHaveCount(2)
        ->and($changes[0]['Action'])->toBe('UPSERT')
        ->and($changes[0]['ResourceRecordSet']['Name'])->toBe('example.com')
        ->and($changes[0]['ResourceRecordSet']['Type'])->toBe('A')
        ->and($changes[0]['ResourceRecordSet']['AliasTarget']['DNSName'])->toBe('alb-1.ap-southeast-2.elb.amazonaws.com')
        ->and($changes[0]['ResourceRecordSet']['AliasTarget']['HostedZoneId'])->toBe('ZALB123')
        ->and($changes[1]['ResourceRecordSet']['Name'])->toBe('www.example.com')
        // the Route 53 SDK's CleanId middleware strips the /hostedzone/ prefix before the call
        ->and($change['args']['HostedZoneId'])->toBe('ZONE1');
});

it('upserts both records for a www-canonical domain (www served, apex redirects)', function (): void {
    $elb = [];
    bindAlbLookup($elb);

    $r53 = [];
    bindMockRoute53Client([['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE1']], $r53);

    recordSetSyncer()->syncRecordSet('example.com', 'www.example.com');

    $changes = collect($r53)->firstWhere('name', 'ChangeResourceRecordSets')['args']['ChangeBatch']['Changes'];

    // Both halves resolve to the ALB; the canonical (www) is served and the apex
    // sibling is 301-redirected by the listener rule.
    expect($changes)->toHaveCount(2)
        ->and(collect($changes)->pluck('ResourceRecordSet.Name')->all())
        ->toEqualCanonicalizing(['www.example.com', 'example.com']);
});

it('upserts a single alias record for a subdomain', function (): void {
    $elb = [];
    bindAlbLookup($elb);

    $r53 = [];
    bindMockRoute53Client([['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE1']], $r53);

    recordSetSyncer()->syncRecordSet('example.com', 'app.example.com');

    $changes = collect($r53)->firstWhere('name', 'ChangeResourceRecordSets')['args']['ChangeBatch']['Changes'];

    expect($changes)->toHaveCount(1)
        ->and($changes[0]['ResourceRecordSet']['Name'])->toBe('app.example.com')
        ->and($changes[0]['ResourceRecordSet']['AliasTarget']['DNSName'])->toBe('alb-1.ap-southeast-2.elb.amazonaws.com');
});
