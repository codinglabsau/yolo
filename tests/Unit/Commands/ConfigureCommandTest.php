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
    $wrote = invokeConfigure(configureCommand(), 'writeProfile', 'my-app-testing', '/usr/local/bin/helper "AWS My App"');

    expect($wrote)->toBeTrue()
        ->and(file_get_contents($this->awsDirectory . '/config'))->toBe(
            "[profile my-app-testing]\n"
            . "credential_process = /usr/local/bin/helper \"AWS My App\"\n"
            . "region = ap-southeast-2\n"
        );
});

it('replaces an SSO-remnant profile after warning, naming the stale keys', function (): void {
    if (! is_dir($this->awsDirectory)) {
        mkdir($this->awsDirectory, 0700, true);
    }
    file_put_contents($this->awsDirectory . '/config', implode("\n", [
        '[profile my-app-testing]',
        'sso_session = corp',
        'sso_account_id = 111111111111',
    ]) . "\n");

    Prompt::fake([Key::ENTER]);

    $wrote = invokeConfigure(configureCommand(), 'writeProfile', 'my-app-testing', '/usr/local/bin/helper "AWS My App"');

    expect($wrote)->toBeTrue()
        ->and(file_get_contents($this->awsDirectory . '/config'))
        ->toContain('credential_process')
        ->not->toContain('sso_session');
});

it('leaves an existing profile untouched when replacement is declined', function (): void {
    if (! is_dir($this->awsDirectory)) {
        mkdir($this->awsDirectory, 0700, true);
    }
    file_put_contents($this->awsDirectory . '/config', "[profile my-app-testing]\nregion = us-west-2\n");

    Prompt::fake(['n', Key::ENTER]);

    $wrote = invokeConfigure(configureCommand(), 'writeProfile', 'my-app-testing', '/usr/local/bin/helper "AWS My App"');

    expect($wrote)->toBeFalse()
        ->and(file_get_contents($this->awsDirectory . '/config'))->toBe("[profile my-app-testing]\nregion = us-west-2\n");
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

    expect($helper)->toBe($localBin . '/yolo-credentials')
        ->and(is_executable($helper))->toBeTrue()
        ->and(file_get_contents($helper))->toContain('yolo-credentials — emit short-lived AWS credentials');
});
