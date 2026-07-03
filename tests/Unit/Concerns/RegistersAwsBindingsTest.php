<?php

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Commands\Command;

it('forgetAwsClients() releases resolved client instances so a fork rebuilds them fresh', function (): void {
    Helpers::app()->instance('s3', $stale = new stdClass());

    expect(Helpers::app()->make('s3'))->toBe($stale);

    Command::forgetAwsClients();

    // Resolving again must never hand back the stale instance — either a fresh
    // client builds from a surviving singleton binding, or nothing is bound at
    // all. Both prove the inherited client (and its sockets) was released.
    $resolved = null;

    try {
        $resolved = Helpers::app()->make('s3');
    } catch (Throwable) {
        // no binding registered in this process — nothing to rebuild from
    }

    expect($resolved)->not->toBe($stale);
});

it('pins AWS_CLIENT_BINDINGS to exactly what registerAwsServices() binds', function (): void {
    Helpers::app()->instance('runningInAws', false);
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    // Off AWS and outside CI, awsCredentials() insists on a named profile.
    $_ENV['YOLO_TESTING_AWS_PROFILE'] = 'pin-test';

    $before = array_keys(Helpers::app()->getBindings());

    $command = new class() extends Command
    {
        protected function configure(): void
        {
            $this->setName('registers-aws-fixture');
        }

        public function register(): void
        {
            $this->registerAwsServices();
        }
    };

    $command->register();

    $registered = array_values(array_diff(array_keys(Helpers::app()->getBindings()), $before));

    // A client registered without being listed in AWS_CLIENT_BINDINGS would be
    // inherited by forked plan workers with the parent's live sockets attached.
    expect($registered)->toEqualCanonicalizing(Command::AWS_CLIENT_BINDINGS);

    unset($_ENV['YOLO_TESTING_AWS_PROFILE']);
});

it('builds every client with a request timeout and standard-mode retries', function (): void {
    Helpers::app()->instance('runningInAws', false);
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    $_ENV['YOLO_TESTING_AWS_PROFILE'] = 'pin-test';

    $command = new class() extends Command
    {
        /** @return array<string, mixed> */
        public static function arguments(): array
        {
            return self::awsClientArguments();
        }
    };

    $arguments = $command::arguments();

    // Without a timeout the SDK waits on a stalled response forever, hanging
    // a forked plan worker; standard-mode retries turn that timeout into a
    // retryable connection error instead of a fatal one.
    expect($arguments['http'])->toBe(['connect_timeout' => 5, 'timeout' => 15])
        ->and($arguments['retries'])->toBe(['mode' => 'standard', 'max_attempts' => 3]);

    unset($_ENV['YOLO_TESTING_AWS_PROFILE']);
});
