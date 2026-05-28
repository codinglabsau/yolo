<?php

use Aws\Result;
use Aws\MockHandler;
use Codinglabs\Yolo\Aws;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use Aws\CloudWatchLogs\CloudWatchLogsClient;

describe('tags', function () {
    it('generates key-value tag format by default', function () {
        $result = Aws::tags(['Name' => 'my-resource']);

        expect($result)->toHaveKey('Tags');
        expect($result['Tags'])->toContain(
            ['Key' => 'yolo:environment', 'Value' => 'testing'],
            ['Key' => 'Name', 'Value' => 'my-resource'],
        );
    });

    it('generates associative tag format when flagged', function () {
        $result = Aws::tags(['Name' => 'my-resource'], wrap: 'tags', associative: true);

        expect($result)->toBe([
            'tags' => [
                'yolo:environment' => 'testing',
                'Name' => 'my-resource',
            ],
        ]);
    });

    it('supports custom wrap key', function () {
        $result = Aws::tags(['Name' => 'test'], wrap: 'TagSet');

        expect($result)->toHaveKey('TagSet');
        expect($result)->not->toHaveKey('Tags');
    });

    it('always includes environment tag', function () {
        $result = Aws::tags(associative: true);

        expect($result['Tags'])->toBe([
            'yolo:environment' => 'testing',
        ]);
    });
});

describe('expectedTags', function () {
    it('returns associative map including environment by default', function () {
        expect(Aws::expectedTags())->toBe(['yolo:environment' => 'testing']);
    });

    it('merges supplied tags over the defaults', function () {
        expect(Aws::expectedTags(['Name' => 'foo']))->toBe([
            'yolo:environment' => 'testing',
            'Name' => 'foo',
        ]);
    });
});

describe('flattenTags', function () {
    it('normalises upper-case Key/Value pairs to associative', function () {
        expect(Aws::flattenTags([
            ['Key' => 'yolo:environment', 'Value' => 'production'],
            ['Key' => 'Name', 'Value' => 'my-app'],
        ]))->toBe([
            'yolo:environment' => 'production',
            'Name' => 'my-app',
        ]);
    });

    it('normalises lower-case key/value pairs to associative', function () {
        expect(Aws::flattenTags([
            ['key' => 'yolo:environment', 'value' => 'production'],
        ]))->toBe(['yolo:environment' => 'production']);
    });

    it('returns an already-associative map unchanged', function () {
        expect(Aws::flattenTags(['Name' => 'foo']))->toBe(['Name' => 'foo']);
    });

    it('returns an empty list as an empty array', function () {
        expect(Aws::flattenTags([]))->toBe([]);
    });
});

describe('synchroniseCloudWatchLogsTags', function () {
    it('strips the stream wildcard `:*` suffix before calling the CloudWatch Logs tag APIs', function () {
        $captured = [];

        Helpers::app()->instance('cloudWatchLogs', new CloudWatchLogsClient([
            'region' => 'ap-southeast-2',
            'version' => 'latest',
            'credentials' => false,
            'handler' => tap(new MockHandler(), function (MockHandler $mock) use (&$captured) {
                $mock->append(function (CommandInterface $cmd) use (&$captured) {
                    $captured[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

                    return new Result(['tags' => []]);
                });
                $mock->append(function (CommandInterface $cmd) use (&$captured) {
                    $captured[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

                    return new Result([]);
                });
            }),
        ]));

        Aws::synchroniseCloudWatchLogsTags(
            'arn:aws:logs:ap-southeast-2:111111111111:log-group:/yolo/my-app:*',
            ['Name' => '/yolo/my-app'],
            apply: true,
        );

        foreach ($captured as $call) {
            expect($call['args']['resourceArn'])
                ->toBe('arn:aws:logs:ap-southeast-2:111111111111:log-group:/yolo/my-app')
                ->not->toEndWith(':*');
        }
    });
});

describe('tagsRequiringSync', function () {
    it('returns tags missing from the current set', function () {
        expect(Aws::tagsRequiringSync(
            expected: ['yolo:environment' => 'production', 'Name' => 'foo'],
            current: ['yolo:environment' => 'production'],
        ))->toBe(['Name' => 'foo']);
    });

    it('returns tags whose values have drifted', function () {
        expect(Aws::tagsRequiringSync(
            expected: ['Name' => 'new-name'],
            current: ['Name' => 'old-name'],
        ))->toBe(['Name' => 'new-name']);
    });

    it('returns empty when current is a superset of expected', function () {
        expect(Aws::tagsRequiringSync(
            expected: ['Name' => 'foo'],
            current: ['Name' => 'foo', 'manual:owner' => 'steve'],
        ))->toBe([]);
    });

    it('does not surface tags only present in current — reconciliation is additive', function () {
        $result = Aws::tagsRequiringSync(
            expected: ['Name' => 'foo'],
            current: ['Name' => 'foo', 'manual:owner' => 'steve', 'cost-center' => 'eng'],
        );

        expect($result)->toBe([]);
    });
});
