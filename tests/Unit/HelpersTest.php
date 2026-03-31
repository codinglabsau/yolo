<?php

use Codinglabs\Yolo\Helpers;
use Symfony\Component\Yaml\Yaml;

$tempDir = sys_get_temp_dir() . '/yolo-test-helpers';

beforeAll(function () use ($tempDir) {
    @mkdir($tempDir, 0755, true);

    if (! defined('BASE_PATH')) {
        define('BASE_PATH', $tempDir);
    }

    file_put_contents($tempDir . '/yolo.yml', Yaml::dump([
        'name' => 'my-app',
        'environments' => [
            'production' => [],
        ],
    ], 10, 2));
});

beforeEach(function () {
    Helpers::app()->instance('environment', 'production');
});

afterAll(function () use ($tempDir) {
    @unlink($tempDir . '/yolo.yml');
    @rmdir($tempDir);
});

describe('keyedResourceName', function () {
    it('generates exclusive name without suffix', function () {
        expect(Helpers::keyedResourceName())
            ->toBe('yolo-production-my-app');
    });

    it('generates exclusive name with suffix', function () {
        expect(Helpers::keyedResourceName('web'))
            ->toBe('yolo-production-my-app-web');
    });

    it('generates non-exclusive name without suffix', function () {
        expect(Helpers::keyedResourceName(exclusive: false))
            ->toBe('yolo-production');
    });

    it('generates non-exclusive name with suffix', function () {
        expect(Helpers::keyedResourceName('ivs-eventbridge-policy', exclusive: false))
            ->toBe('yolo-production-ivs-eventbridge-policy');
    });

    it('supports custom separator', function () {
        expect(Helpers::keyedResourceName('queue', seperator: '/'))
            ->toBe('yolo/production/my-app/queue');
    });

    it('resolves backed enum values', function () {
        $enum = new class('web')
        {
            public function __construct(public string $value) {}
        };

        // BackedEnum check uses instanceof, so test with a string directly
        expect(Helpers::keyedResourceName('web'))
            ->toBe('yolo-production-my-app-web');
    });
});

describe('payloadHasDifferences', function () {
    it('returns false for identical payloads', function () {
        $payload = ['key' => 'value', 'nested' => ['a' => 1]];

        expect(Helpers::payloadHasDifferences($payload, $payload))
            ->toBeFalse();
    });

    it('detects missing keys', function () {
        expect(Helpers::payloadHasDifferences(
            ['key' => 'value', 'extra' => 'data'],
            ['key' => 'value'],
        ))->toBeTrue();
    });

    it('detects value changes', function () {
        expect(Helpers::payloadHasDifferences(
            ['key' => 'new'],
            ['key' => 'old'],
        ))->toBeTrue();
    });

    it('detects nested differences', function () {
        expect(Helpers::payloadHasDifferences(
            ['nested' => ['a' => 1, 'b' => 2]],
            ['nested' => ['a' => 1, 'b' => 3]],
        ))->toBeTrue();
    });

    it('ignores extra keys in actual', function () {
        expect(Helpers::payloadHasDifferences(
            ['key' => 'value'],
            ['key' => 'value', 'extra' => 'ignored'],
        ))->toBeFalse();
    });

    it('detects type mismatches in nested values', function () {
        expect(Helpers::payloadHasDifferences(
            ['key' => ['a' => 1]],
            ['key' => 'not-an-array'],
        ))->toBeTrue();
    });
});
