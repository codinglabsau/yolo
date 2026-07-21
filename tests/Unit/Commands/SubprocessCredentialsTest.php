<?php

use Codinglabs\Yolo\Helpers;
use Aws\Credentials\Credentials;
use Codinglabs\Yolo\Commands\RunCommand;

/**
 * Expose the protected subprocess-credential helpers — the contract that keeps
 * every shelled-out session (`run`, `db:tunnel`, `db:cutover` execs) on the
 * minted tier credentials instead of the operator's base profile.
 */
function subprocessProbe(): object
{
    return new class() extends RunCommand
    {
        public function exposedEnv(): ?array
        {
            return $this->subprocessEnv();
        }

        public function exposedProfile(): ?string
        {
            return $this->subprocessProfile();
        }
    };
}

afterEach(function (): void {
    Helpers::app()->forgetInstance('yoloAssumedCredentials');
    unset($_ENV['YOLO_TESTING_AWS_PROFILE']);
});

it('hands the minted tier credentials to the subprocess and suppresses the profile', function (): void {
    Helpers::app()->instance('yoloAssumedCredentials', new Credentials('AKIDEXAMPLE', 'secret', 'token'));

    expect(subprocessProbe()->exposedEnv())->toBe([
        'AWS_ACCESS_KEY_ID' => 'AKIDEXAMPLE',
        'AWS_SECRET_ACCESS_KEY' => 'secret',
        'AWS_SESSION_TOKEN' => 'token',
    ]);

    expect(subprocessProbe()->exposedProfile())->toBeNull();
});

it('falls back to the base profile only when no tier credentials were minted', function (): void {
    $_ENV['YOLO_TESTING_AWS_PROFILE'] = 'base-profile';

    expect(subprocessProbe()->exposedEnv())->toBeNull();
    expect(subprocessProbe()->exposedProfile())->toBe('base-profile');
});
