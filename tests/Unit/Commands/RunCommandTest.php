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

/**
 * Simulate the SSM agent's handling of a one-off command: it does NOT run the
 * string through a shell — it shellwords-splits it (quotes group, an unquoted
 * backslash escapes the next byte) and execs the resulting argv directly.
 *
 * @return array<int, string>
 */
function agentArgv(string $command): array
{
    $argv = [];
    $current = '';
    $started = false;

    for ($i = 0, $length = strlen($command); $i < $length; $i++) {
        $char = $command[$i];

        if ($char === "'") {
            $started = true;
            while (++$i < $length && $command[$i] !== "'") {
                $current .= $command[$i];
            }
        } elseif ($char === '"') {
            $started = true;
            while (++$i < $length && $command[$i] !== '"') {
                $current .= $command[$i] === '\\' ? $command[++$i] : $command[$i];
            }
        } elseif ($char === '\\') {
            $started = true;
            $current .= $command[++$i];
        } elseif ($char === ' ' || $char === "\t") {
            if ($started) {
                $argv[] = $current;
                $current = '';
                $started = false;
            }
        } else {
            $started = true;
            $current .= $char;
        }
    }

    if ($started) {
        $argv[] = $current;
    }

    return $argv;
}

it('encodes a one-off command as an explicit sh -c over a base64 decode-and-run pipeline', function (): void {
    $command = "php artisan tinker --execute='\\App\\Models\\Foo::bar()'";

    $encoded = RunCommand::encodeCommand($command);

    expect($encoded)->toBe(sprintf("sh -c 'echo %s | base64 -d | sh'", base64_encode($command)));
    expect($encoded)->toMatch('/^sh -c \'echo [A-Za-z0-9+\/=]+ \| base64 -d \| sh\'$/');
});

it('tokenises to a three-element sh -c argv under the agent parse', function (): void {
    // The pipeline must arrive as ONE argument to `sh -c`. A bare pipeline
    // (no sh -c wrapper) tokenises to `echo` plus literal arguments — under
    // direct exec that prints the pipeline text and exits 0 without ever
    // running the command: a silent no-op that reports success.
    $argv = agentArgv(RunCommand::encodeCommand('php artisan migrate:fresh --seed --force'));

    expect($argv)->toHaveCount(3);
    expect(array_slice($argv, 0, 2))->toBe(['sh', '-c']);
    expect($argv[2])->toMatch('/^echo [A-Za-z0-9+\/=]+ \| base64 -d \| sh$/');
});

it('survives the agent parse plus direct exec byte-for-byte', function (): void {
    // A namespaced PHP one-liner, properly single-quoted for the one shell
    // that's meant to finally run it — the "Class not found" shape reduces to
    // this: printf stands in for `php artisan tinker --execute=...`, and
    // `\App\Foo` stands in for the namespaced class. Raw, the agent's
    // tokeniser strips its quoting and consumes the backslashes before any
    // shell sees it; encoded, the payload is pure `[A-Za-z0-9+/=]`, so the
    // parse has nothing to reinterpret and the original bytes reach the final
    // decode intact.
    $command = "printf %s '\\App\\Foo'";

    $process = new Process(agentArgv(RunCommand::encodeCommand($command)));
    $process->mustRun();

    expect($process->getOutput())->toBe('\App\Foo');
});

it('propagates the exit code of the decoded command through the pipeline', function (): void {
    $process = new Process(agentArgv(RunCommand::encodeCommand('exit 7')));
    $process->run();

    expect($process->getExitCode())->toBe(7);
});
