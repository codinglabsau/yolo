<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Aws\EventBridge\EventBridgeClient;
use Codinglabs\Yolo\Resources\EventBridge\IvsEventBridgeRule;

/**
 * Bind an EventBridge client returning the supplied DescribeRule shape and
 * recording every command name. Returns the recorder for `$recorder->calls`.
 *
 * @param  array<string, mixed>  $rule
 */
function bindRecordingEventBridgeClient(array $rule): object
{
    $recorder = new class($rule) extends MockHandler
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function __construct(public array $rule) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = $cmd->getName();

            return Create::promiseFor(match ($cmd->getName()) {
                'DescribeRule' => new Result($this->rule),
                default => new Result([]),
            });
        }
    };

    Helpers::app()->instance('eventBridge', new EventBridgeClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $recorder,
    ]));

    return $recorder;
}

function liveIvsRule(array $overrides = []): array
{
    return array_merge([
        'Name' => 'yolo-testing-my-app-ivs-state-change',
        'Arn' => 'arn:aws:events:ap-southeast-2:111111111111:rule/yolo-testing-my-app-ivs-state-change',
        'EventPattern' => json_encode(['source' => ['aws.ivs']]),
        'State' => 'ENABLED',
        'Description' => 'YOLO managed IVS state change events',
    ], $overrides);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'ivs' => true,
    ]);
});

it('returns no change and does not put the rule when it already matches', function (): void {
    $recorder = bindRecordingEventBridgeClient(liveIvsRule());

    expect((new IvsEventBridgeRule())->synchroniseConfiguration())->toBe([]);
    expect($recorder->calls)->not->toContain('PutRule');
});

it('detects a state drift and re-puts the rule', function (): void {
    $recorder = bindRecordingEventBridgeClient(liveIvsRule(['State' => 'DISABLED']));

    $changes = (new IvsEventBridgeRule())->synchroniseConfiguration();

    expect($changes)->toHaveCount(1);
    expect($changes[0]->attribute)->toBe('state');
    expect($changes[0]->from)->toBe('DISABLED');
    expect($changes[0]->to)->toBe('ENABLED');
    expect($recorder->calls)->toContain('PutRule');
});

it('ignores event-pattern key ordering but detects a real pattern change', function (): void {
    // Same pattern, no drift.
    $recorder = bindRecordingEventBridgeClient(liveIvsRule(['EventPattern' => json_encode(['source' => ['aws.ivs']])]));
    expect((new IvsEventBridgeRule())->synchroniseConfiguration())->toBe([]);

    // A genuinely different pattern is drift.
    bindRecordingEventBridgeClient(liveIvsRule(['EventPattern' => json_encode(['source' => ['aws.s3']])]));
    expect(collect((new IvsEventBridgeRule())->synchroniseConfiguration())->pluck('attribute'))->toContain('event-pattern');
});

it('computes the diff without writing under apply:false', function (): void {
    $recorder = bindRecordingEventBridgeClient(liveIvsRule(['State' => 'DISABLED']));

    expect((new IvsEventBridgeRule())->synchroniseConfiguration(apply: false))->toHaveCount(1);
    expect($recorder->calls)->not->toContain('PutRule');
});
