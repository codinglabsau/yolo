<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Commands\RunCommand;

it('builds the execute-command invocation with a profile', function (): void {
    $args = RunCommand::executeCommandArgs(
        cluster: 'yolo-production-codinglabs',
        task: 'arn:aws:ecs:ap-southeast-2:111:task/abc',
        command: '/bin/sh',
        container: 'web',
        region: 'ap-southeast-2',
        profile: 'codinglabs',
    );

    expect($args)->toBe([
        'aws', 'ecs', 'execute-command',
        '--cluster', 'yolo-production-codinglabs',
        '--task', 'arn:aws:ecs:ap-southeast-2:111:task/abc',
        '--container', 'web',
        '--interactive',
        '--command', '/bin/sh',
        '--region', 'ap-southeast-2',
        '--profile', 'codinglabs',
    ]);
});

it('targets the container named after the service group', function (): void {
    $args = RunCommand::executeCommandArgs(
        cluster: 'yolo-production-codinglabs',
        task: 'task-arn',
        command: '/bin/sh',
        container: 'queue',
        region: 'ap-southeast-2',
        profile: null,
    );

    expect($args)->toContain('--container', 'queue');
});

it('omits --profile when none is configured (e.g. running on AWS)', function (): void {
    $args = RunCommand::executeCommandArgs(
        cluster: 'yolo-production-codinglabs',
        task: 'task-arn',
        command: 'php artisan migrate --force',
        container: 'web',
        region: 'ap-southeast-2',
        profile: null,
    );

    expect($args)->not->toContain('--profile');
    expect($args)->toContain('--command', 'php artisan migrate --force');
});

it('encodes a one-off command as a base64 decode-and-run pipeline', function (): void {
    $command = "php artisan tinker --execute='\\App\\Models\\Foo::bar()'";

    $encoded = RunCommand::encodeCommand($command);

    expect($encoded)->toBe('echo ' . base64_encode($command) . ' | base64 -d | sh');
    expect($encoded)->toMatch('/^echo [A-Za-z0-9+\/=]+ \| base64 -d \| sh$/');
});

it('survives a naive re-quoting hop that mangles a namespaced one-liner', function (): void {
    // A namespaced PHP one-liner, properly single-quoted for the one shell
    // that's meant to finally run it — the exact "Class not found" shape from
    // the bug report reduces to this: printf stands in for `php artisan
    // tinker --execute=...`, and `\App\Foo` stands in for the namespaced class.
    $command = "printf %s '\\App\\Foo'";

    // Confirm that shape is correct for a single parse — this is what the
    // documented workaround (attach interactively, paste the command
    // directly) gets right: exactly one shell reads it, so the single quotes
    // do their job.
    $direct = new Process(['sh', '-c', $command]);
    $direct->mustRun();
    expect($direct->getOutput())->toBe('\App\Foo');

    // Simulate the extra hop between the terminal and the container: something
    // in the chain (the AWS CLI, the SSM plugin, ECS's own exec wrapper — the
    // exact culprit is opaque and undocumented) re-embeds the command inside
    // another single-quoted shell string, the way a wrapper built by naive
    // string concatenation would. `$command` already contains single quotes
    // of its own, so they collide with the wrapper's — the shell closes the
    // outer quote early, spilling `\App\Foo` into unquoted territory where a
    // bare backslash is consumed rather than preserved.
    $naivelyReEmbed = fn (string $text): string => "sh -c '{$text}'";

    $mangled = new Process(['sh', '-c', $naivelyReEmbed($command)]);
    $mangled->mustRun();
    expect($mangled->getOutput())->toBe('AppFoo');

    // The fix: wrap it first. The payload is pure `[A-Za-z0-9+/=]` — no single
    // quotes, no backslashes — so the same naive re-embedding has nothing to
    // collide with, and the original bytes reach the final decode intact.
    $wrapped = RunCommand::encodeCommand($command);
    expect($wrapped)->not->toContain("'");

    $fixed = new Process(['sh', '-c', $naivelyReEmbed($wrapped)]);
    $fixed->mustRun();
    expect($fixed->getOutput())->toBe('\App\Foo');
});

it('propagates the exit code of the decoded command through the pipeline', function (): void {
    $wrapped = RunCommand::encodeCommand('exit 7');

    $process = new Process(['sh', '-c', $wrapped]);
    $process->run();

    expect($process->getExitCode())->toBe(7);
});
