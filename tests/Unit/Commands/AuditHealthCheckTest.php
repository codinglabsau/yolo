<?php

declare(strict_types=1);

use Aws\Result;
use Laravel\Prompts\Prompt;
use Aws\Command as AwsCommand;
use Codinglabs\Yolo\Audit\Audit;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Commands\AuditCommand;
use Codinglabs\Yolo\Commands\AuditAppCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Commands\AbstractAuditCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Commands\AuditEnvironmentCommand;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'app-db']);
    // concludeHealth() prints findings via Laravel Prompts' global output — capture
    // it so the suite stays quiet and the calls don't touch a real terminal.
    Prompt::setOutput(new BufferedOutput());
});

/** @return array<int, string> */
function healthCheckErrors(AbstractAuditCommand $command): array
{
    return (new ReflectionProperty($command, 'errors'))->getValue($command);
}

function callAuditMethod(AbstractAuditCommand $command, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($command, $method))->invoke($command, ...$args);
}

describe('exit-code contract', function (): void {
    it('exits 0 (healthy) when there are no findings', function (): void {
        $command = new AuditEnvironmentCommand();

        expect(callAuditMethod($command, 'concludeHealth'))->toBe(AuditEnvironmentCommand::SUCCESS);
    });

    it('exits 1 when an unexpected resource is in scope — any finding fails', function (): void {
        $command = new AuditEnvironmentCommand();

        callAuditMethod($command, 'flagUnexpected', [
            'resources' => [
                ['status' => Audit::STATUS_UNEXPECTED, 'scope' => Audit::SCOPE_ENV, 'app' => null],
            ],
        ]);

        expect(callAuditMethod($command, 'concludeHealth'))->toBe(AuditEnvironmentCommand::FAILURE);
    });

    it('does not let warnings affect the exit code — only errors do', function (): void {
        $command = new AuditEnvironmentCommand();

        callAuditMethod($command, 'recordWarning', 'just so you know');

        expect(healthCheckErrors($command))->toBeEmpty()
            ->and(callAuditMethod($command, 'concludeHealth'))->toBe(AuditEnvironmentCommand::SUCCESS);
    });

    it('scopes the unexpected-resource finding to the audited app, not the whole env', function (): void {
        $command = new AuditAppCommand();
        $command->input = new ArrayInput(['environment' => 'testing', 'app' => 'mine'], $command->getDefinition());

        callAuditMethod($command, 'flagUnexpected', [
            'resources' => [
                ['status' => Audit::STATUS_UNEXPECTED, 'scope' => Audit::SCOPE_APP, 'app' => 'someone-else'],
            ],
        ]);

        // The stray belongs to another app, so it's out of scope — no error.
        expect(healthCheckErrors($command))->toBeEmpty();
    });
});

describe('RDS deletion-protection finding', function (): void {
    it('records an error when deletion protection is off', function (): void {
        $captured = [];
        bindMockRdsClient([
            'DescribeDBInstances' => new Result(['DBInstances' => [['DBInstanceIdentifier' => 'app-db', 'DeletionProtection' => false]]]),
        ], $captured);

        $command = new AuditCommand();
        callAuditMethod($command, 'inspectRds', false);

        expect(healthCheckErrors($command))->toHaveCount(1)
            ->and(healthCheckErrors($command)[0])->toContain('deletion protection DISABLED');
    });

    it('records no finding when deletion protection is on', function (): void {
        $captured = [];
        bindMockRdsClient([
            'DescribeDBInstances' => new Result(['DBInstances' => [['DBInstanceIdentifier' => 'app-db', 'DeletionProtection' => true]]]),
        ], $captured);

        $command = new AuditCommand();
        callAuditMethod($command, 'inspectRds', false);

        expect(healthCheckErrors($command))->toBeEmpty()
            ->and($command->recordedWarnings())->toBeEmpty();
    });

    it('records a warning, not an error, when the database cannot be read', function (): void {
        $captured = [];
        bindMockRdsClient([
            'DescribeDBInstances' => new RdsException('not found', new AwsCommand('DescribeDBInstances'), ['code' => 'DBInstanceNotFound']),
        ], $captured);

        $command = new AuditCommand();
        callAuditMethod($command, 'inspectRds', false);

        expect(healthCheckErrors($command))->toBeEmpty()
            ->and($command->recordedWarnings())->toHaveCount(1)
            ->and($command->recordedWarnings()[0])->toContain('deletion protection unconfirmed');
    });

    it('records nothing and returns null when no database is declared', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

        $command = new AuditCommand();

        expect(callAuditMethod($command, 'inspectRds', false))->toBeNull()
            ->and(healthCheckErrors($command))->toBeEmpty();
    });
});
