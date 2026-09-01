<?php

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Commands\ConfigureCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function configureCommand(): ConfigureCommand
{
    return new ConfigureCommand();
}

function invokeConfigure(ConfigureCommand $command, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($command, $method))->invoke($command, ...$arguments);
}

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    $this->awsDirectory = BASE_PATH . '/aws-configure-test';
    Helpers::app()->instance('awsDirectory', $this->awsDirectory);

    array_map(unlink(...), glob($this->awsDirectory . '/*') ?: []);

    if (file_exists(BASE_PATH . '/.env')) {
        unlink(BASE_PATH . '/.env');
    }

    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;
});

it('writes a fresh profile block with the manifest region', function (): void {
    invokeConfigure(configureCommand(), 'writeProfile', 'my-app-testing', '/usr/local/bin/helper "AWS My App"');

    expect(file_get_contents($this->awsDirectory . '/config'))->toBe(
        "[profile my-app-testing]\n"
        . "credential_process = /usr/local/bin/helper \"AWS My App\"\n"
        . "region = ap-southeast-2\n"
    );
});

it('defaults an already-configured profile to verify-only, not reconfigure', function (): void {
    if (! is_dir($this->awsDirectory)) {
        mkdir($this->awsDirectory, 0700, true);
    }
    file_put_contents($this->awsDirectory . '/config', "[profile my-app-testing]\ncredential_process = /usr/local/bin/helper\nregion = us-west-2\n");

    Prompt::fake([Key::ENTER]);

    expect(invokeConfigure(configureCommand(), 'confirmReconfigure', 'my-app-testing'))->toBeFalse();
});

it('reconfigures an already-configured profile when opted in', function (): void {
    if (! is_dir($this->awsDirectory)) {
        mkdir($this->awsDirectory, 0700, true);
    }
    file_put_contents($this->awsDirectory . '/config', "[profile my-app-testing]\ncredential_process = /usr/local/bin/helper\nregion = us-west-2\n");

    Prompt::fake(['y', Key::ENTER]);

    expect(invokeConfigure(configureCommand(), 'confirmReconfigure', 'my-app-testing'))->toBeTrue();
});

it('defaults to reconfigure when the existing profile carries SSO remnants', function (): void {
    if (! is_dir($this->awsDirectory)) {
        mkdir($this->awsDirectory, 0700, true);
    }
    file_put_contents($this->awsDirectory . '/config', implode("\n", [
        '[profile my-app-testing]',
        'sso_session = corp',
        'sso_account_id = 111111111111',
    ]) . "\n");

    // ENTER accepts the default — reconfigure, because SSO remnants would
    // otherwise steer resolution away from credential_process. Contrast the
    // healthy-profile case above, where the same ENTER leaves it as verify-only.
    Prompt::fake([Key::ENTER]);

    expect(invokeConfigure(configureCommand(), 'confirmReconfigure', 'my-app-testing'))->toBeTrue();
});

it('removes a shadowing static-key section on confirmation', function (): void {
    if (! is_dir($this->awsDirectory)) {
        mkdir($this->awsDirectory, 0700, true);
    }
    file_put_contents($this->awsDirectory . '/credentials', implode("\n", [
        '[my-app-testing]',
        'aws_access_key_id = AKIAEXAMPLE',
        'aws_secret_access_key = example',
        '',
        '[unrelated]',
        'aws_access_key_id = AKIAEXAMPLE2',
    ]) . "\n");

    Prompt::fake([Key::ENTER]);

    invokeConfigure(configureCommand(), 'ensureNoShadowingStaticKeys', 'my-app-testing');

    expect(file_get_contents($this->awsDirectory . '/credentials'))
        ->not->toContain('[my-app-testing]')
        ->toContain('[unrelated]');
});

it('leaves the shadowing section when removal is declined, warning loudly', function (): void {
    if (! is_dir($this->awsDirectory)) {
        mkdir($this->awsDirectory, 0700, true);
    }
    file_put_contents($this->awsDirectory . '/credentials', "[my-app-testing]\naws_access_key_id = AKIAEXAMPLE\n");

    Prompt::fake(['n', Key::ENTER]);

    invokeConfigure(configureCommand(), 'ensureNoShadowingStaticKeys', 'my-app-testing');

    expect(file_get_contents($this->awsDirectory . '/credentials'))->toContain('[my-app-testing]');
});

it('is silent when no shadowing section exists', function (): void {
    invokeConfigure(configureCommand(), 'ensureNoShadowingStaticKeys', 'my-app-testing');

    expect(file_exists($this->awsDirectory . '/credentials'))->toBeFalse();
});

it('appends the keyed profile variable to a fresh .env', function (): void {
    invokeConfigure(configureCommand(), 'writeEnvProfile', 'my-app-testing');

    expect(file_get_contents(BASE_PATH . '/.env'))->toBe("YOLO_TESTING_AWS_PROFILE=my-app-testing\n");
});

it('replaces an existing keyed profile line in place', function (): void {
    file_put_contents(BASE_PATH . '/.env', "APP_NAME=my-app\nYOLO_TESTING_AWS_PROFILE=old-profile\nAPP_DEBUG=false\n");

    invokeConfigure(configureCommand(), 'writeEnvProfile', 'my-app-testing');

    expect(file_get_contents(BASE_PATH . '/.env'))->toBe(
        "APP_NAME=my-app\nYOLO_TESTING_AWS_PROFILE=my-app-testing\nAPP_DEBUG=false\n"
    );
});

it('rejects an unknown --driver value', function (): void {
    $command = configureCommand();
    $command->input = new ArrayInput(
        ['environment' => 'testing', '--driver' => 'lastpass'],
        $command->getDefinition(),
    );

    expect(invokeConfigure($command, 'resolveDriver'))->toBeNull()
        ->and(test()->promptOutput->fetch())->toContain('1password, process');
});

it('installs the bundled helper executable into the local bin directory', function (): void {
    $localBin = BASE_PATH . '/local-bin-test';
    Helpers::app()->instance('localBinDirectory', $localBin);
    array_map(unlink(...), glob($localBin . '/*') ?: []);

    $helper = invokeConfigure(configureCommand(), 'installHelper');

    expect($helper)->toBe($localBin . '/yolo-credentials-1password')
        ->and(is_executable($helper))->toBeTrue()
        ->and(file_get_contents($helper))->toContain('yolo-credentials-1password — emit short-lived AWS credentials');
});

it('passes the MFA gate when a device and TOTP are both present', function (): void {
    expect(invokeConfigure(configureCommand(), 'enforceMfaPosture', true, true))->toBeTrue()
        ->and(test()->promptOutput->fetch())->toContain('MFA-forwarded');
});

it('fails the MFA gate when a device is registered but the item has no TOTP', function (): void {
    expect(invokeConfigure(configureCommand(), 'enforceMfaPosture', true, false))->toBeFalse()
        ->and(test()->promptOutput->fetch())->toContain('no one-time-password field');
});

it('fails the MFA gate when no device is registered on the user', function (): void {
    expect(invokeConfigure(configureCommand(), 'enforceMfaPosture', false, null))->toBeFalse()
        ->and(test()->promptOutput->fetch())->toContain('No MFA device is registered');
});

it('passes with a warning when the device check itself is denied', function (): void {
    expect(invokeConfigure(configureCommand(), 'enforceMfaPosture', null, true))->toBeTrue()
        ->and(test()->promptOutput->fetch())->toContain('iam:ListMFADevices was denied');
});

it('passes with a forwarding reminder for the custom-process driver', function (): void {
    expect(invokeConfigure(configureCommand(), 'enforceMfaPosture', true, null))->toBeTrue()
        ->and(test()->promptOutput->fetch())->toContain('forwards a TOTP');
});
