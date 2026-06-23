<?php

use Aws\Command;
use Codinglabs\Yolo\Aws\WafV2;
use Aws\Exception\AwsException;

function wafUnavailable(): AwsException
{
    return new AwsException('AWS WAF couldn\'t retrieve the resource', new Command('CreateWebACL'), ['code' => 'WAFUnavailableEntityException']);
}

it('retries an unavailable WAF entity until it succeeds', function (): void {
    $calls = 0;

    $result = WafV2::retryWhileUnavailable(function () use (&$calls): string {
        $calls++;

        if ($calls < 3) {
            throw wafUnavailable();
        }

        return 'created';
    }, maxAttempts: 5, sleepSeconds: 0);

    expect($result)->toBe('created')
        ->and($calls)->toBe(3);
});

it('gives up and rethrows after the maximum attempts', function (): void {
    $calls = 0;

    // Real closure (not an arrow fn) so the &$calls reference reaches the test scope.
    $run = function () use (&$calls): mixed {
        return WafV2::retryWhileUnavailable(function () use (&$calls): string {
            $calls++;

            throw wafUnavailable();
        }, maxAttempts: 3, sleepSeconds: 0);
    };

    expect($run)->toThrow(AwsException::class);
    expect($calls)->toBe(3);
});

it('rethrows a non-unavailable AWS error immediately without retrying', function (): void {
    $calls = 0;

    $run = function () use (&$calls): mixed {
        return WafV2::retryWhileUnavailable(function () use (&$calls): string {
            $calls++;

            throw new AwsException('bad input', new Command('CreateWebACL'), ['code' => 'WAFInvalidParameterException']);
        }, maxAttempts: 5, sleepSeconds: 0);
    };

    expect($run)->toThrow(AwsException::class);
    expect($calls)->toBe(1);
});

it('retries past WAFAssociatedItemException (the web ACL delete racing the disassociate)', function (): void {
    $calls = 0;

    $result = WafV2::retryWhileAssociated(function () use (&$calls): string {
        if (++$calls < 3) {
            throw new AwsException('still associated', new Command('DeleteWebACL'), ['code' => 'WAFAssociatedItemException']);
        }

        return 'deleted';
    }, maxAttempts: 5, sleepSeconds: 0);

    expect($result)->toBe('deleted')
        ->and($calls)->toBe(3);
});

it('rethrows a non-association error from retryWhileAssociated without retrying', function (): void {
    $calls = 0;

    $run = function () use (&$calls): mixed {
        return WafV2::retryWhileAssociated(function () use (&$calls): string {
            $calls++;

            throw new AwsException('bad input', new Command('DeleteWebACL'), ['code' => 'WAFInvalidParameterException']);
        }, maxAttempts: 5, sleepSeconds: 0);
    };

    expect($run)->toThrow(AwsException::class);
    expect($calls)->toBe(1);
});
