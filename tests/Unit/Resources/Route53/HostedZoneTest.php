<?php

declare(strict_types=1);

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use Aws\Route53\Route53Client;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Commands\SyncAppCommand;
use Codinglabs\Yolo\Resources\Route53\HostedZone;

/**
 * Bind a mock Route 53 client: ListHostedZones returns one zone for the apex,
 * ListTagsForResource returns the supplied tag map, and ChangeTagsForResource
 * (and anything else) resolves empty. Every call is captured so the AddTags
 * batch can be asserted.
 *
 * @param  array<string, string>  $zoneTags
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindTaggedRoute53Client(string $apex, array $zoneTags, array &$captured): void
{
    $mock = new class($apex, $zoneTags, $captured) extends MockHandler
    {
        /**
         * @param  array<string, string>  $zoneTags
         * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
         */
        public function __construct(protected string $apex, protected array $zoneTags, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            return Create::promiseFor(match ($cmd->getName()) {
                'ListHostedZones' => new Result(['HostedZones' => [
                    ['Id' => '/hostedzone/Z123', 'Name' => "{$this->apex}."],
                ]]),
                'ListTagsForResource' => new Result(['ResourceTagSet' => [
                    'Tags' => collect($this->zoneTags)
                        ->map(fn (string $value, string $key): array => ['Key' => $key, 'Value' => $value])
                        ->values()
                        ->all(),
                ]]),
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

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'domain' => 'example.com.au']);
});

it('reports a sibling environment as the owner of a shared zone', function (): void {
    $captured = [];
    bindTaggedRoute53Client('example.com.au', ['yolo:environment' => 'production'], $captured);

    expect((new HostedZone('example.com.au'))->ownerEnvironment())->toBe('production');
});

it('is not owned by a sibling when the zone carries this environment or no env tag', function (string $env): void {
    $captured = [];
    bindTaggedRoute53Client('example.com.au', $env === '' ? [] : ['yolo:environment' => $env], $captured);

    expect((new HostedZone('example.com.au'))->ownerEnvironment())->toBeNull();
})->with(['this env' => 'testing', 'untagged' => '']);

it('never overwrites a sibling environment\'s ownership tag', function (): void {
    $captured = [];
    bindTaggedRoute53Client('example.com.au', [
        'yolo:environment' => 'production',
        'yolo:app' => 'my-app',
        'yolo:scope' => 'app',
        'Name' => 'example.com.au',
    ], $captured);

    (new HostedZone('example.com.au'))->synchroniseTags(apply: true);

    // The zone is fully tagged by the incumbent, so nothing is written at all —
    // and critically the env tag is never in an AddTags batch (no flap, no drift).
    $writes = collect($captured)->where('name', 'ChangeTagsForResource');
    expect($writes)->toBeEmpty();
});

it('stamps this environment onto an untagged adopted zone', function (): void {
    $captured = [];
    bindTaggedRoute53Client('example.com.au', [], $captured);

    $missing = (new HostedZone('example.com.au'))->synchroniseTags(apply: true);

    expect($missing)->toHaveKey('yolo:environment', 'testing');

    $write = collect($captured)->firstWhere('name', 'ChangeTagsForResource');
    $added = collect($write['args']['AddTags'])->pluck('Value', 'Key');
    expect($added)->toHaveKey('yolo:environment', 'testing');
});

it('warns on sync when the hosted zone is owned by another environment', function (): void {
    $captured = [];
    bindTaggedRoute53Client('example.com.au', ['yolo:environment' => 'production'], $captured);

    expect((new SyncAppCommand())->hostedZoneOwnershipWarning())
        ->toContain('example.com.au', 'production');
});

it('gives no ownership warning when the zone is unowned or this environment owns it', function (): void {
    $captured = [];
    bindTaggedRoute53Client('example.com.au', ['yolo:environment' => 'testing'], $captured);

    expect((new SyncAppCommand())->hostedZoneOwnershipWarning())->toBeNull();
});

it('gives no ownership warning for headless or multi-tenant apps (no single apex zone)', function (array $config): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', ...$config]);

    expect((new SyncAppCommand())->hostedZoneOwnershipWarning())->toBeNull();
})->with([
    'headless' => [['tasks' => ['web' => true]]],
    'multi-tenant' => [['tenants' => ['alpha' => []]]],
]);
