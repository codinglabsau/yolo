<?php

use Codinglabs\Yolo\Helpers;

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
