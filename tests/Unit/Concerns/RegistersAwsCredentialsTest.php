<?php

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Concerns\RegistersAws;

/**
 * awsCredentials() and usingLongLivedAccessKeys() are protected static; reach
 * them through a tiny trait-using proxy.
 */
function credentialsProxy(): object
{
    return new class()
    {
        use RegistersAws;

        public static function credentials(): callable|array|null
        {
            return self::awsCredentials();
        }

        public static function longLivedKeys(): bool
        {
            return self::usingLongLivedAccessKeys();
        }

        public static function requiresProfile(): bool
        {
            return self::requiresAwsProfile();
        }
    };
}

beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
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

function setEnv(array $values): void
{
    foreach ($values as $key => $value) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

it('defers to the SDK default credential chain in CI so every auth method works', function () {
    // OIDC / assumed-role creds (session token present) — the SDK env provider
    // reads key + secret + token itself, so YOLO returns null and stays out of it.
    setEnv([
        'CI' => 'true',
        'AWS_ACCESS_KEY_ID' => 'ASIAEXAMPLE',
        'AWS_SECRET_ACCESS_KEY' => 'secret-access-key',
        'AWS_SESSION_TOKEN' => 'session-token-value',
    ]);

    expect(credentialsProxy()::credentials())->toBeNull();
});

it('flags long-lived static access keys (key present, no session token)', function () {
    setEnv([
        'CI' => 'true',
        'AWS_ACCESS_KEY_ID' => 'AKIAEXAMPLE',
        'AWS_SECRET_ACCESS_KEY' => 'secret-access-key',
    ]);

    expect(credentialsProxy()::longLivedKeys())->toBeTrue();
});

it('does not flag OIDC / assumed-role creds as long-lived', function () {
    setEnv([
        'CI' => 'true',
        'AWS_ACCESS_KEY_ID' => 'ASIAEXAMPLE',
        'AWS_SECRET_ACCESS_KEY' => 'secret-access-key',
        'AWS_SESSION_TOKEN' => 'session-token-value',
    ]);

    expect(credentialsProxy()::longLivedKeys())->toBeFalse();
});

it('does not flag the web-identity / SSO path where no static key is exported', function () {
    setEnv(['CI' => 'true']);

    expect(credentialsProxy()::longLivedKeys())->toBeFalse();
});

it('requires an AWS profile for a genuinely local run', function () {
    // beforeEach binds runningInAws=false; ensure CI is unset so this is "local".
    putenv('CI');
    unset($_ENV['CI'], $_SERVER['CI']);

    expect(credentialsProxy()::requiresProfile())->toBeTrue();
});

it('does not require an AWS profile in CI', function () {
    setEnv(['CI' => 'true']);

    expect(credentialsProxy()::requiresProfile())->toBeFalse();
});

it('does not require an AWS profile on AWS', function () {
    Helpers::app()->instance('runningInAws', true);

    expect(credentialsProxy()::requiresProfile())->toBeFalse();
});
