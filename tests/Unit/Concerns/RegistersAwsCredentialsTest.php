<?php

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Concerns\RegistersAws;

/**
 * awsCredentials() and requiresAwsProfile() are protected static; reach them
 * through a tiny trait-using proxy.
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
    putenv('CI');
    unset($_ENV['CI'], $_SERVER['CI']);
});

function setEnv(array $values): void
{
    foreach ($values as $key => $value) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

it('defers to the SDK default credential chain in CI', function () {
    // The SDK's own providers resolve whatever the runner exported (GitHub OIDC
    // / SSO), so YOLO returns null and stays out of it.
    setEnv(['CI' => 'true']);

    expect(credentialsProxy()::credentials())->toBeNull();
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
