<?php

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Concerns\RegistersAws;

/**
 * awsCredentials() is protected static; reach it through a tiny trait-using proxy.
 */
function resolveAwsCredentials(): callable|array|null
{
    $proxy = new class()
    {
        use RegistersAws;

        public static function credentials(): callable|array|null
        {
            return self::awsCredentials();
        }
    };

    return $proxy::credentials();
}

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);

    // awsCredentials() short-circuits to the IAM-role path when on AWS — force
    // the off-AWS path so the CI branch is reached.
    Helpers::app()->instance('runningInAws', false);
});

afterEach(function () {
    foreach (['CI', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

it('passes the session token in CI so OIDC assumed-role credentials are accepted', function () {
    foreach ([
        'CI' => 'true',
        'AWS_ACCESS_KEY_ID' => 'ASIAEXAMPLE',
        'AWS_SECRET_ACCESS_KEY' => 'secret-access-key',
        'AWS_SESSION_TOKEN' => 'session-token-value',
    ] as $key => $value) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    expect(resolveAwsCredentials())->toBe([
        'key' => 'ASIAEXAMPLE',
        'secret' => 'secret-access-key',
        'token' => 'session-token-value',
    ]);
});

it('leaves the token null for the legacy static-key path (backwards compatible)', function () {
    foreach ([
        'CI' => 'true',
        'AWS_ACCESS_KEY_ID' => 'AKIAEXAMPLE',
        'AWS_SECRET_ACCESS_KEY' => 'secret-access-key',
    ] as $key => $value) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    expect(resolveAwsCredentials())->toBe([
        'key' => 'AKIAEXAMPLE',
        'secret' => 'secret-access-key',
        'token' => null,
    ]);
});
