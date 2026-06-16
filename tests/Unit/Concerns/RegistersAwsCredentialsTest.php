<?php

use Codinglabs\Yolo\Helpers;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialsInterface;
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

        public static function credentials(): CredentialsInterface|callable|array|null
        {
            return self::awsCredentials();
        }

        public static function requiresProfile(): bool
        {
            return self::requiresAwsProfile();
        }
    };
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    // awsCredentials() short-circuits to the IAM-role path when on AWS — force
    // the off-AWS path so the CI branch is reached.
    Helpers::app()->instance('runningInAws', false);
});

afterEach(function (): void {
    putenv('CI');
    unset($_ENV['CI'], $_SERVER['CI']);

    // The minted-credentials binding is a process singleton — forget it so a
    // case that binds it can't leak the Credentials object into a sibling.
    Helpers::app()->forgetInstance('yoloAssumedCredentials');
});

function setEnv(array $values): void
{
    foreach ($values as $key => $value) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

it('defers to the SDK default credential chain in CI', function (): void {
    // The SDK's own providers resolve whatever the runner exported (GitHub OIDC
    // / SSO), so YOLO returns null and stays out of it.
    setEnv(['CI' => 'true']);

    expect(credentialsProxy()::credentials())->toBeNull();
});

it('returns the minted tier Credentials object once a tier has assumed a role', function (): void {
    // After mintTierCredentials() binds the assumed-role Credentials, every client
    // re-registers against them. awsCredentials() must hand back that object — a
    // CredentialsInterface, which the SDK accepts but the old callable|array|null
    // return type rejected with a TypeError (silently swallowed → ran on profile).
    $credentials = new Credentials('ASIA-ADMIN', 'admin-secret', 'admin-session-token');
    Helpers::app()->instance('yoloAssumedCredentials', $credentials);

    expect(credentialsProxy()::credentials())
        ->toBeInstanceOf(CredentialsInterface::class)
        ->toBe($credentials);
});

it('requires an AWS profile for a genuinely local run', function (): void {
    // beforeEach binds runningInAws=false; ensure CI is unset so this is "local".
    putenv('CI');
    unset($_ENV['CI'], $_SERVER['CI']);

    expect(credentialsProxy()::requiresProfile())->toBeTrue();
});

it('does not require an AWS profile in CI', function (): void {
    setEnv(['CI' => 'true']);

    expect(credentialsProxy()::requiresProfile())->toBeFalse();
});

it('does not require an AWS profile on AWS', function (): void {
    Helpers::app()->instance('runningInAws', true);

    expect(credentialsProxy()::requiresProfile())->toBeFalse();
});
