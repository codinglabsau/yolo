<?php

use Codinglabs\Yolo\Helpers;

describe('keyedResourceName', function () {
    it('generates exclusive name without suffix', function () {
        expect(Helpers::keyedResourceName())
            ->toBe('yolo-testing-my-app');
    });

    it('generates exclusive name with suffix', function () {
        expect(Helpers::keyedResourceName('web'))
            ->toBe('yolo-testing-my-app-web');
    });

    it('generates non-exclusive name without suffix', function () {
        expect(Helpers::keyedResourceName(exclusive: false))
            ->toBe('yolo-testing');
    });

    it('generates non-exclusive name with suffix', function () {
        expect(Helpers::keyedResourceName('ivs-eventbridge-policy', exclusive: false))
            ->toBe('yolo-testing-ivs-eventbridge-policy');
    });

    it('supports custom separator', function () {
        expect(Helpers::keyedResourceName('queue', seperator: '/'))
            ->toBe('yolo/testing/my-app/queue');
    });
});

describe('parseGithubRepository', function () {
    it('parses the ssh remote form', function () {
        expect(Helpers::parseGithubRepository('git@github.com:codinglabsau/codinglabs.git'))
            ->toBe('codinglabsau/codinglabs');
    });

    it('parses the https remote form', function () {
        expect(Helpers::parseGithubRepository('https://github.com/codinglabsau/codinglabs.git'))
            ->toBe('codinglabsau/codinglabs');
    });

    it('parses without the trailing .git', function () {
        expect(Helpers::parseGithubRepository('https://github.com/codinglabsau/codinglabs'))
            ->toBe('codinglabsau/codinglabs');
    });

    it('parses the ssh:// scheme form', function () {
        expect(Helpers::parseGithubRepository('ssh://git@github.com/codinglabsau/codinglabs.git'))
            ->toBe('codinglabsau/codinglabs');
    });

    it('returns null for a non-GitHub remote', function () {
        expect(Helpers::parseGithubRepository('git@gitlab.com:codinglabsau/codinglabs.git'))
            ->toBeNull();
    });

    it('returns null for a null url', function () {
        expect(Helpers::parseGithubRepository(null))->toBeNull();
    });
});

describe('keyedEnvName', function () {
    it('formats environment variable name', function () {
        expect(Helpers::keyedEnvName('DB_HOST'))
            ->toBe('YOLO_TESTING_DB_HOST');
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
