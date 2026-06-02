<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\Sns\SnsClient;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Resources\CloudWatch\QueueAlarm;

/** Bind an SNS client whose ListTopics always returns the alarm topic. */
function bindAlarmTopic(): void
{
    $result = new Result([
        'Topics' => [
            ['TopicArn' => 'arn:aws:sns:ap-southeast-2:111111111111:yolo-testing'],
        ],
    ]);

    $mock = new class($result) extends MockHandler
    {
        public function __construct(protected Result $result) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor($this->result);
        }
    };

    Helpers::app()->instance('sns', new SnsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

beforeEach(function () {
    writeManifest(['region' => 'ap-southeast-2', 'account-id' => '111111111111']);
    bindAlarmTopic();
});

it('reconciles the app-scoped ownership tags onto the alarm (PutMetricAlarm cannot tag an existing one)', function () {
    $arn = 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:yolo-testing-my-app-queue-depth-alarm';

    $captured = [];
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [
            ['AlarmName' => 'yolo-testing-my-app-queue-depth-alarm', 'AlarmArn' => $arn],
        ]]),
        'ListTagsForResource' => new Result(['Tags' => []]),
    ], $captured);

    (new QueueAlarm(
        alarmName: 'yolo-testing-my-app-queue-depth-alarm',
        queueName: 'yolo-testing-my-app',
    ))->synchronise();

    expect(collect($captured)->pluck('name'))->toContain('PutMetricAlarm', 'TagResource');

    $tagCall = collect($captured)->firstWhere('name', 'TagResource');
    expect($tagCall['args']['ResourceARN'])->toBe($arn);

    $tags = collect($tagCall['args']['Tags'])->mapWithKeys(fn ($t) => [$t['Key'] => $t['Value']]);
    expect($tags)->toMatchArray([
        'yolo:environment' => 'testing',
        'yolo:scope' => 'app',
        'yolo:app' => 'my-app',
        'Name' => 'yolo-testing-my-app-queue-depth-alarm',
    ]);
});

it('makes no tag write when the alarm already carries the expected tags', function () {
    $arn = 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:yolo-testing-my-app-queue-depth-alarm';

    $captured = [];
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [
            ['AlarmName' => 'yolo-testing-my-app-queue-depth-alarm', 'AlarmArn' => $arn],
        ]]),
        'ListTagsForResource' => new Result(['Tags' => [
            ['Key' => 'yolo:environment', 'Value' => 'testing'],
            ['Key' => 'yolo:scope', 'Value' => 'app'],
            ['Key' => 'yolo:app', 'Value' => 'my-app'],
            ['Key' => 'Name', 'Value' => 'yolo-testing-my-app-queue-depth-alarm'],
        ]]),
    ], $captured);

    (new QueueAlarm(
        alarmName: 'yolo-testing-my-app-queue-depth-alarm',
        queueName: 'yolo-testing-my-app',
    ))->synchronise();

    expect(collect($captured)->pluck('name'))->not->toContain('TagResource');
});
