<?php

declare(strict_types=1);

use Codinglabs\Yolo\Yolo;
use Codinglabs\Yolo\Contracts\AdminCommand;
use Codinglabs\Yolo\Commands\DbCutoverCommand;

it('is named db:cutover', function (): void {
    expect((new DbCutoverCommand())->getName())->toBe('db:cutover');
});

it('is registered in the application', function (): void {
    $commands = (new ReflectionClass(Yolo::class))->getDefaultProperties()['commands'];

    expect($commands)->toContain(DbCutoverCommand::class);
});

it('runs under the admin tier — the flip rewrites live runtime config across the fleet', function (): void {
    expect(new DbCutoverCommand())->toBeInstanceOf(AdminCommand::class);
});

it('wraps a shell line in the exec agent wire format with inner quotes escaped', function (): void {
    expect(DbCutoverCommand::containerCommand("grep '^DB_HOST=' .env"))
        ->toBe('/bin/sh -c "grep \'^DB_HOST=\' .env"');

    expect(DbCutoverCommand::containerCommand('php artisan tinker --execute="echo DB::scalar(\'select @@server_uuid\');"'))
        ->toBe('/bin/sh -c "php artisan tinker --execute=\"echo DB::scalar(\'select @@server_uuid\');\""');
});

it('strips SSM session banner lines and blank lines from exec output', function (): void {
    $raw = "\nStarting session with SessionId: ecs-execute-command-abc123\nDB_HOST=db.example.rds.amazonaws.com\n\nExiting session with sessionId: ecs-execute-command-abc123.\n";

    expect(DbCutoverCommand::cleanOutput($raw))->toBe('DB_HOST=db.example.rds.amazonaws.com');
});

it('patches DB_HOST and rebuilds the cached config in one in-container command', function (): void {
    expect(DbCutoverCommand::patchCommand('new-db.example.rds.amazonaws.com'))
        ->toBe("sed -i 's|^DB_HOST=.*|DB_HOST=new-db.example.rds.amazonaws.com|' .env && php artisan optimize");
});

it('parses the DB_HOST value out of exec output', function (): void {
    expect(DbCutoverCommand::parseEnvHost('DB_HOST=old-db.example.rds.amazonaws.com'))->toBe('old-db.example.rds.amazonaws.com')
        ->and(DbCutoverCommand::parseEnvHost("noise\nDB_HOST=db.internal\nmore"))->toBe('db.internal')
        ->and(DbCutoverCommand::parseEnvHost('no match here'))->toBeNull()
        ->and(DbCutoverCommand::parseEnvHost(null))->toBeNull();
});

it('parses a server uuid out of a live-query answer', function (): void {
    expect(DbCutoverCommand::parseServerUuid('3f1c2a4b-9d8e-4f00-a1b2-c3d4e5f60718'))
        ->toBe('3f1c2a4b-9d8e-4f00-a1b2-c3d4e5f60718')
        ->and(DbCutoverCommand::parseServerUuid('not a uuid'))->toBeNull()
        ->and(DbCutoverCommand::parseServerUuid(null))->toBeNull();
});

it('accepts hostnames and rejects sed-hostile input for the target host', function (): void {
    expect(DbCutoverCommand::validHost('new-db.abc123.ap-southeast-2.rds.amazonaws.com'))->toBeTrue()
        ->and(DbCutoverCommand::validHost('my-db-2'))->toBeTrue()
        ->and(DbCutoverCommand::validHost('host|payload'))->toBeFalse()
        ->and(DbCutoverCommand::validHost("host'"))->toBeFalse()
        ->and(DbCutoverCommand::validHost('host with spaces'))->toBeFalse()
        ->and(DbCutoverCommand::validHost(''))->toBeFalse();
});

it('plans a flip for tasks on the old host and a skip for tasks already on the target', function (): void {
    $rows = DbCutoverCommand::planRows([
        ['group' => 'web', 'arn' => 'arn:task/aaa', 'id' => 'aaa', 'host' => 'old-db.internal'],
        ['group' => 'queue', 'arn' => 'arn:task/bbb', 'id' => 'bbb', 'host' => 'new-db.internal'],
        ['group' => 'web', 'arn' => 'arn:task/ccc', 'id' => 'ccc', 'host' => null],
    ], 'new-db.internal');

    expect($rows)->toBe([
        ['web', 'aaa', 'old-db.internal', 'flip → new-db.internal'],
        ['queue', 'bbb', 'new-db.internal', 'already on target — skip'],
        ['web', 'ccc', '(unreadable)', 'flip → new-db.internal'],
    ]);
});

it('verifies the env line, cached config, live query and maintenance mode on every group', function (): void {
    $checks = DbCutoverCommand::verifyChecks('web', 'new-db.internal');

    expect(array_keys($checks))->toBe(['.env DB_HOST', 'cached config host', 'live query answered', 'maintenance mode off'])
        ->and($checks['.env DB_HOST'][0])->toBe("grep '^DB_HOST=' .env")
        ->and($checks['cached config host'][0])->toBe('php artisan config:show database.connections.mysql.host');

    expect(preg_match($checks['.env DB_HOST'][1], 'DB_HOST=new-db.internal'))->toBe(1)
        ->and(preg_match($checks['.env DB_HOST'][1], 'DB_HOST=old-db.internal'))->toBe(0)
        ->and(preg_match($checks['live query answered'][1], '3f1c2a4b-9d8e-4f00-a1b2-c3d4e5f60718'))->toBe(1)
        ->and(preg_match($checks['maintenance mode off'][1], 'Maintenance Mode ......... OFF'))->toBe(1);
});

it('additionally proves queue workers are running on queue tasks', function (): void {
    $checks = DbCutoverCommand::verifyChecks('queue', 'new-db.internal');

    expect($checks)->toHaveKey('queue workers running')
        ->and(preg_match($checks['queue workers running'][1], '4'))->toBe(1)
        ->and(preg_match($checks['queue workers running'][1], '0'))->toBe(0);

    expect(DbCutoverCommand::verifyChecks('scheduler', 'new-db.internal'))->not->toHaveKey('queue workers running');
});
