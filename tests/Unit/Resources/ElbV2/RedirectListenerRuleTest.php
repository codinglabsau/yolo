<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\ElbV2\RedirectListenerRule;

function redirectRule(): RedirectListenerRule
{
    return new RedirectListenerRule('arn:listener');
}

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

describe('hosts', function (): void {
    it('redirects the www sibling when the apex is canonical', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'domain' => 'example.com',
        ]);

        expect(redirectRule()->hosts())->toBe(['www.example.com']);
    });

    it('redirects the apex sibling when www is canonical', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'apex' => 'tenant.com', 'domain' => 'www.tenant.com',
        ]);

        expect(redirectRule()->hosts())->toBe(['tenant.com']);
    });
});

it('creates a 301 redirect to the canonical host, preserving path and query', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'example.com',
    ]);

    $captured = [];
    bindRoutedElbV2Client(['DescribeRules' => new Result(['Rules' => []])], $captured);

    redirectRule()->create();

    $create = collect($captured)->firstWhere('name', 'CreateRule');

    expect($create)->not->toBeNull();

    $condition = $create['args']['Conditions'][0];
    $action = $create['args']['Actions'][0];

    expect($condition['Field'])->toBe('host-header')
        ->and($condition['HostHeaderConfig']['Values'])->toBe(['www.example.com'])
        ->and($action['Type'])->toBe('redirect')
        ->and($action['RedirectConfig']['Host'])->toBe('example.com')
        ->and($action['RedirectConfig']['StatusCode'])->toBe('HTTP_301')
        ->and($action['RedirectConfig']['Protocol'])->toBe('HTTPS')
        ->and($action['RedirectConfig']['Port'])->toBe('443')
        ->and($action['RedirectConfig']['Path'])->toBe('/#{path}')
        ->and($action['RedirectConfig']['Query'])->toBe('#{query}')
        ->and($create['args']['Priority'])->toBeGreaterThanOrEqual(1000);
});

it('reconciles a swapped redirect rule in place (host + redirect target)', function (): void {
    // Switched from apex-canonical to www-canonical: the redirect rule (matched by
    // its stable Name) must flip from "www → apex" to "apex → www" in place.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'apex' => 'tenant.com', 'domain' => 'www.tenant.com',
    ]);

    $captured = [];
    bindRoutedElbV2Client([
        'DescribeRules' => new Result(['Rules' => [[
            'RuleArn' => 'arn:rule:redirect',
            'Priority' => '2000',
            'Conditions' => [['Field' => 'host-header', 'HostHeaderConfig' => ['Values' => ['www.tenant.com']]]],
            'Actions' => [['Type' => 'redirect', 'RedirectConfig' => ['Host' => 'tenant.com', 'StatusCode' => 'HTTP_301']]],
        ]]]),
        'DescribeTags' => new Result(['TagDescriptions' => [
            ['ResourceArn' => 'arn:rule:redirect', 'Tags' => [['Key' => 'Name', 'Value' => 'yolo-testing-my-app-redirect']]],
        ]]),
    ], $captured);

    $changes = redirectRule()->synchroniseConfiguration(apply: true);
    $modify = collect($captured)->where('name', 'ModifyRule')->first();

    expect($changes)->toHaveCount(2)
        ->and($modify['args']['RuleArn'])->toBe('arn:rule:redirect')
        ->and($modify['args']['Conditions'][0]['HostHeaderConfig']['Values'])->toBe(['tenant.com'])
        ->and($modify['args']['Actions'][0]['RedirectConfig']['Host'])->toBe('www.tenant.com');
});
