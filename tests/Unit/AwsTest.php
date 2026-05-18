<?php

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\ServerGroup;

describe('serverGroup', function () {
    afterEach(function () {
        Helpers::app()->instance('runningInAwsWebEnvironment', false);
        Helpers::app()->instance('runningInAwsQueueEnvironment', false);
        Helpers::app()->instance('runningInAwsSchedulerEnvironment', false);
    });

    it('returns WEB when running in the web environment', function () {
        Helpers::app()->instance('runningInAwsWebEnvironment', true);
        Helpers::app()->instance('runningInAwsQueueEnvironment', false);
        Helpers::app()->instance('runningInAwsSchedulerEnvironment', false);

        expect(Aws::serverGroup())->toBe(ServerGroup::WEB);
    });

    it('returns QUEUE when running in the queue environment', function () {
        Helpers::app()->instance('runningInAwsWebEnvironment', false);
        Helpers::app()->instance('runningInAwsQueueEnvironment', true);
        Helpers::app()->instance('runningInAwsSchedulerEnvironment', false);

        expect(Aws::serverGroup())->toBe(ServerGroup::QUEUE);
    });

    it('returns SCHEDULER when running in the scheduler environment', function () {
        Helpers::app()->instance('runningInAwsWebEnvironment', false);
        Helpers::app()->instance('runningInAwsQueueEnvironment', false);
        Helpers::app()->instance('runningInAwsSchedulerEnvironment', true);

        expect(Aws::serverGroup())->toBe(ServerGroup::SCHEDULER);
    });

    it('returns null when not running in any known server environment', function () {
        Helpers::app()->instance('runningInAwsWebEnvironment', false);
        Helpers::app()->instance('runningInAwsQueueEnvironment', false);
        Helpers::app()->instance('runningInAwsSchedulerEnvironment', false);

        expect(Aws::serverGroup())->toBeNull();
    });
});

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
