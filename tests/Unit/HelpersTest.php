<?php

declare(strict_types=1);

use Codinglabs\Yolo\Helpers;

describe('keyedResourceName', function (): void {
    it('generates exclusive name without suffix', function (): void {
        expect(Helpers::keyedResourceName())
            ->toBe('yolo-testing-my-app');
    });

    it('generates exclusive name with suffix', function (): void {
        expect(Helpers::keyedResourceName('web'))
            ->toBe('yolo-testing-my-app-web');
    });

    it('generates non-exclusive name without suffix', function (): void {
        expect(Helpers::keyedResourceName(exclusive: false))
            ->toBe('yolo-testing');
    });

    it('generates non-exclusive name with suffix', function (): void {
        expect(Helpers::keyedResourceName('ivs-eventbridge-policy', exclusive: false))
            ->toBe('yolo-testing-ivs-eventbridge-policy');
    });

});

describe('keyedBucketName', function (): void {
    beforeEach(function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
    });

    // S3 names are globally unique across every AWS account, so bucket names
    // carry the account id where other resource names don't.
    it('generates exclusive bucket name with suffix', function (): void {
        expect(Helpers::keyedBucketName('config'))
            ->toBe('yolo-111111111111-testing-my-app-config');
    });

    it('generates exclusive bucket name without suffix', function (): void {
        expect(Helpers::keyedBucketName())
            ->toBe('yolo-111111111111-testing-my-app');
    });

    it('generates non-exclusive bucket name without suffix', function (): void {
        expect(Helpers::keyedBucketName(exclusive: false))
            ->toBe('yolo-111111111111-testing');
    });

    it('generates non-exclusive bucket name with suffix', function (): void {
        expect(Helpers::keyedBucketName('logs', exclusive: false))
            ->toBe('yolo-111111111111-testing-logs');
    });
});

describe('parseGithubRepository', function (): void {
    it('parses the ssh remote form', function (): void {
        expect(Helpers::parseGithubRepository('git@github.com:codinglabsau/codinglabs.git'))
            ->toBe('codinglabsau/codinglabs');
    });

    it('parses the https remote form', function (): void {
        expect(Helpers::parseGithubRepository('https://github.com/codinglabsau/codinglabs.git'))
            ->toBe('codinglabsau/codinglabs');
    });

    it('parses without the trailing .git', function (): void {
        expect(Helpers::parseGithubRepository('https://github.com/codinglabsau/codinglabs'))
            ->toBe('codinglabsau/codinglabs');
    });

    it('parses the ssh:// scheme form', function (): void {
        expect(Helpers::parseGithubRepository('ssh://git@github.com/codinglabsau/codinglabs.git'))
            ->toBe('codinglabsau/codinglabs');
    });

    it('returns null for a non-GitHub remote', function (): void {
        expect(Helpers::parseGithubRepository('git@gitlab.com:codinglabsau/codinglabs.git'))
            ->toBeNull();
    });

    it('returns null for a null url', function (): void {
        expect(Helpers::parseGithubRepository(null))->toBeNull();
    });
});

describe('keyedEnvName', function (): void {
    it('formats environment variable name', function (): void {
        expect(Helpers::keyedEnvName('DB_HOST'))
            ->toBe('YOLO_TESTING_DB_HOST');
    });
});

describe('truncate', function (): void {
    it('returns a short string unchanged', function (): void {
        expect(Helpers::truncate('hello', 20))->toBe('hello');
    });

    it('truncates with an ellipsis to exactly the width', function (): void {
        $result = Helpers::truncate('hello world', 5);

        expect($result)->toBe('hell…')
            ->and(mb_strlen($result))->toBe(5);
    });

    it('folds whitespace runs — tabs and newlines — to single spaces', function (): void {
        expect(Helpers::truncate("a\tb\n\nc   d", 80))->toBe('a b c d');
    });

    it('strips raw ANSI escape sequences before measuring', function (): void {
        expect(Helpers::truncate("\e[31mred\e[0m", 80))->toBe('red');
    });

    it('returns an empty string for a non-positive width', function (): void {
        expect(Helpers::truncate('anything', 0))->toBe('');
    });
});

describe('payloadHasDifferences', function (): void {
    it('returns false for identical payloads', function (): void {
        $payload = ['key' => 'value', 'nested' => ['a' => 1]];

        expect(Helpers::payloadHasDifferences($payload, $payload))
            ->toBeFalse();
    });

    it('detects missing keys', function (): void {
        expect(Helpers::payloadHasDifferences(
            ['key' => 'value', 'extra' => 'data'],
            ['key' => 'value'],
        ))->toBeTrue();
    });

    it('detects value changes', function (): void {
        expect(Helpers::payloadHasDifferences(
            ['key' => 'new'],
            ['key' => 'old'],
        ))->toBeTrue();
    });

    it('detects nested differences', function (): void {
        expect(Helpers::payloadHasDifferences(
            ['nested' => ['a' => 1, 'b' => 2]],
            ['nested' => ['a' => 1, 'b' => 3]],
        ))->toBeTrue();
    });

    it('ignores extra keys in actual', function (): void {
        expect(Helpers::payloadHasDifferences(
            ['key' => 'value'],
            ['key' => 'value', 'extra' => 'ignored'],
        ))->toBeFalse();
    });

    it('detects type mismatches in nested values', function (): void {
        expect(Helpers::payloadHasDifferences(
            ['key' => ['a' => 1]],
            ['key' => 'not-an-array'],
        ))->toBeTrue();
    });
});

describe('queue names', function (): void {
    it('is a single un-suffixed queue per scope with no queues: block', function (): void {
        writeManifest([]);

        // solo — one queue at the pre-queues: name, and no --queue chain (the bare worker)
        expect(Helpers::queueNames())->toBe(['yolo-testing-my-app']);
        expect(Helpers::queueChain())->toBeNull();
        expect(Helpers::defaultQueueName())->toBe('yolo-testing-my-app');

        // multi-tenant scopes keep their existing per-scope names, and a per-scope
        // worker draining exactly that one queue
        expect(Helpers::queueNames('landlord'))->toBe(['yolo-testing-my-app-landlord']);
        expect(Helpers::queueChain('landlord'))->toBe('yolo-testing-my-app-landlord');
        expect(Helpers::queueNames('acme'))->toBe(['yolo-testing-my-app-acme']);
        expect(Helpers::queueChain('acme'))->toBe('yolo-testing-my-app-acme');
    });

    it('suffixes the higher tiers but leaves the default tier as the naked scope name', function (): void {
        writeManifest(['queues' => ['high', 'default']]);

        // `default` is Laravel's default queue — the naked scope name, so declaring
        // tiers only adds the `-high` lane; the base queue name is unchanged.
        expect(Helpers::queueNames())->toBe([
            'yolo-testing-my-app-high',
            'yolo-testing-my-app',
        ]);
        expect(Helpers::queueNames('landlord'))->toBe([
            'yolo-testing-my-app-landlord-high',
            'yolo-testing-my-app-landlord',
        ]);
        expect(Helpers::queueNames('acme'))->toBe([
            'yolo-testing-my-app-acme-high',
            'yolo-testing-my-app-acme',
        ]);
    });

    it('chains the tiers comma-separated so queue:work drains them strict-priority', function (): void {
        writeManifest(['queues' => ['high', 'default']]);

        expect(Helpers::queueChain('acme'))
            ->toBe('yolo-testing-my-app-acme-high,yolo-testing-my-app-acme');
    });

    it('resolves the default queue to the naked scope name a producer lands un-routed jobs on', function (): void {
        writeManifest(['queues' => ['high', 'default']]);

        expect(Helpers::defaultQueueName())->toBe('yolo-testing-my-app');
        expect(Helpers::defaultQueueName('acme'))->toBe('yolo-testing-my-app-acme');
    });
});
