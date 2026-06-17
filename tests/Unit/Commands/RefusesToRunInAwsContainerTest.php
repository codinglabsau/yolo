<?php

use Codinglabs\Yolo\Commands\Command;
use Codinglabs\Yolo\Concerns\RegistersAws;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The yolo CLI ships inside the deployed image (its service provider exposes a
 * runtime API) but must never be *executed* there — it would hold the task role's
 * credentials. detectAwsEnvironment() keys off the ECS task-metadata env var (exact
 * and instant, where the old EC2 IMDS probe read false on Fargate), and the base
 * command hard-refuses on it. detectAwsEnvironment() is protected static; reach it
 * through a tiny proxy.
 */
function awsEnvironmentProxy(): object
{
    return new class()
    {
        use RegistersAws;

        public static function detect(): bool
        {
            return self::detectAwsEnvironment();
        }
    };
}

afterEach(function (): void {
    putenv('ECS_CONTAINER_METADATA_URI_V4');
});

it('detects the AWS environment from the ECS task-metadata env var', function (): void {
    putenv('ECS_CONTAINER_METADATA_URI_V4=http://169.254.170.2/v4/task-abc');

    expect(awsEnvironmentProxy()::detect())->toBeTrue();
});

it('is not the AWS environment when the ECS env var is absent (local / CI / build runner)', function (): void {
    // getenv() returns false when unset — a dev machine, CI and the deploy runner all
    // lack this var, so the CLI runs normally there.
    expect(awsEnvironmentProxy()::detect())->toBeFalse();
});

it('hard-refuses to run any command inside a deployed AWS container', function (): void {
    putenv('ECS_CONTAINER_METADATA_URI_V4=http://169.254.170.2/v4/task-abc');

    $command = new class() extends Command
    {
        protected function configure(): void
        {
            $this->setName('aws-guard-fixture');
        }

        public function handle(): int
        {
            return self::SUCCESS;
        }
    };

    // The base command bails before any manifest/environment work, so the fixture's
    // handle() never runs — a non-zero exit is the contract.
    expect((new CommandTester($command))->execute([]))->toBe(1);
});
