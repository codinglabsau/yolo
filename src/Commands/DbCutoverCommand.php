<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Illuminate\Support\Str;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\Rds\Exception\RdsException;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Contracts\AdminCommand;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * In-place database endpoint cutover for a running app — the migration
 * endgame move. `DB_HOST` is baked into the image, so repointing a database
 * via env:push + deploy costs the full rolling-deploy duration as the write
 * window, with a mixed-fleet moment where old and new tasks write to
 * different hosts. This flips every running task in place instead:
 * maintenance page up → patch `.env` per container → rebuild the config
 * cache → `octane:reload` (web) / `queue:restart` (queue) → maintenance
 * page down — shrinking the window to the length of the loop — then proves
 * the result across independent layers, ending with a cross-container
 * `@@server_uuid` identity check that catches a straggler still on the old
 * database even when every hostname reads clean.
 *
 * Admin tier: the flip rewrites the live runtime configuration of every
 * task in the fleet — beyond the deploy lifecycle — and the target picker
 * needs the RDS describe surface only the admin/observer read set carries.
 * Admin's MFA-per-run gate is the right friction for a database cutover.
 * The container execs themselves ride the same `aws ecs execute-command`
 * plumbing as `yolo run` (session-manager-plugin, ECS Exec enabled).
 *
 * THE FLIP IS TRANSIENT: env lives in the baked image, so any task the
 * scheduler replaces afterwards boots the OLD host. Follow promptly with
 * `yolo env:push` + a deploy (and repoint `database:` in the manifest) to
 * make it permanent.
 */
class DbCutoverCommand extends Command implements AdminCommand
{
    /**
     * Task groups flipped, in execution order. The queue group also gets a
     * `queue:restart` (workers hold a booted config in memory); web gets an
     * `octane:reload` for the same reason; scheduler containers boot fresh
     * per cron tick, so patch + optimize is enough there.
     */
    protected const GROUPS = ['web', 'queue', 'scheduler'];

    /** Manual-entry sentinel for the target picker. */
    protected const MANUAL_ENTRY = '__manual';

    protected function configure(): void
    {
        $this
            ->setName('db:cutover')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The target database endpoint or instance identifier (skips the picker)')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Verify-only: prove every container is on the target host and exit non-zero on any failure — read-only and safe to re-run')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'A public URL to probe (expects HTTP 200) at the end of verification')
            ->setDescription('Flip every running task onto a new database host in place, then verify the fleet');
    }

    public function handle(): int
    {
        if (! (new ExecutableFinder())->find('session-manager-plugin')) {
            error("session-manager-plugin isn't installed — run `yolo init` (or see the AWS docs) before using `yolo db:cutover`.");

            return self::FAILURE;
        }

        if (($target = $this->resolveTargetHost()) === null) {
            return self::FAILURE;
        }

        $cluster = (new EcsCluster())->name();
        $tasks = $this->gatherTasks($cluster);

        if ($tasks === []) {
            error(sprintf('No running tasks found for %s — nothing to cut over.', Manifest::name()));

            return self::FAILURE;
        }

        intro(sprintf('yolo db:cutover · %s · %s → %s', Helpers::environment(), Manifest::name(), $target));

        if ($this->option('verify')) {
            return $this->verifyFleet($cluster, $tasks, $target);
        }

        // Read each container's live DB_HOST up front: it renders the plan's
        // old → new column and powers the resumability skip — a task already
        // on the target is a no-op, so a cutover that died mid-loop is safe
        // to simply re-run.
        foreach ($tasks as $index => $task) {
            $tasks[$index]['host'] = spin(
                fn (): ?string => static::parseEnvHost($this->exec($cluster, $task['arn'], $task['group'], "grep '^DB_HOST=' .env")),
                sprintf('Reading DB_HOST on %s %s...', $task['group'], $task['id']),
            );
        }

        $pending = array_values(array_filter($tasks, fn (array $task): bool => $task['host'] !== $target));

        table(['Group', 'Task', 'Current host', 'Action'], static::planRows($tasks, $target));

        if ($pending === []) {
            note('Every task is already on the target host — skipping straight to verification.');

            return $this->verifyFleet($cluster, $tasks, $target);
        }

        warning('Before proceeding, freeze writes on the SOURCE database so a straggler task or in-flight queue job fails loudly instead of writing to the old side.');
        warning('If the source still replicates to the target: REVOKE is binlogged and replicates too (re-GRANT on the target after it arrives), and read_only=1 on a SHARED parameter group freezes both instances at once.');
        warning('The flip is transient — env lives in the baked image, so replaced tasks boot the OLD host until you env:push + deploy.');

        if (! confirm(sprintf('Take %d task(s) down and flip them to %s?', count($pending), $target), default: false)) {
            note('Aborted — nothing was changed.');

            return self::FAILURE;
        }

        if (! $this->flip($cluster, $tasks, $pending, $target)) {
            error('Cutover interrupted — the maintenance page may still be up on some tasks. Re-running is safe: tasks already on the target are skipped and every task is brought back up.');

            return self::FAILURE;
        }

        return $this->verifyFleet($cluster, $tasks, $target);
    }

    /**
     * The flip itself, phased exactly like the battle-tested loop: every
     * pending task goes down first (one write-window, not one per task), then
     * each is patched and its long-lived workers reloaded, then — only once
     * every container proves it sees the new host — every task comes back up.
     * Returns false on the first failed exec, leaving maintenance up so the
     * operator re-runs rather than serving split-brained.
     */
    protected function flip(string $cluster, array $tasks, array $pending, string $target): bool
    {
        foreach ($pending as $task) {
            $result = spin(
                fn (): ?string => $this->exec($cluster, $task['arn'], $task['group'], 'php artisan down --retry=30'),
                sprintf('[down] %s %s', $task['group'], $task['id']),
            );

            if ($result === null) {
                return false;
            }
        }

        foreach ($pending as $task) {
            $patched = spin(
                fn (): ?string => $this->exec($cluster, $task['arn'], $task['group'], static::patchCommand($target)),
                sprintf('[patch] %s %s', $task['group'], $task['id']),
            );

            if ($patched === null) {
                return false;
            }

            // Long-lived workers hold the old config in booted memory; the
            // scheduler boots fresh each cron tick, so nothing to reload there.
            $reload = match ($task['group']) {
                'web' => 'php artisan octane:reload',
                'queue' => 'php artisan queue:restart',
                default => null,
            };

            if ($reload !== null && spin(
                fn (): ?string => $this->exec($cluster, $task['arn'], $task['group'], $reload),
                sprintf('[reload] %s %s', $task['group'], $task['id']),
            ) === null) {
                return false;
            }
        }

        // The pre-up gate: no task serves traffic again until its container
        // proves the patched host landed. A mismatch here means the sed or
        // the optimize didn't take — stop with the page still up.
        foreach ($pending as $task) {
            $seen = spin(
                fn (): ?string => static::parseEnvHost($this->exec($cluster, $task['arn'], $task['group'], "grep '^DB_HOST=' .env")),
                sprintf('[check] %s %s', $task['group'], $task['id']),
            );

            if ($seen !== $target) {
                error(sprintf('%s %s reports DB_HOST=%s after patching — expected %s.', $task['group'], $task['id'], $seen ?? '(unreadable)', $target));

                return false;
            }
        }

        // Up runs across ALL tasks, not just the pending set: a re-run after a
        // mid-loop death finds already-patched tasks still holding the page.
        foreach ($tasks as $task) {
            if (spin(
                fn (): ?string => $this->exec($cluster, $task['arn'], $task['group'], 'php artisan up'),
                sprintf('[up] %s %s', $task['group'], $task['id']),
            ) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The read-only verification pass — also the whole of `--verify`. Per
     * container it proves independent layers: the `.env` line, the CACHED
     * config value (what the booted app actually reads), a live query
     * answering (and which server answered it), maintenance mode off, and
     * running workers on queue tasks. Then the split-brain detector: every
     * container must have reported the SAME `@@server_uuid` — one straggler
     * still talking to the old database fails this even when every hostname
     * reads clean. Exits non-zero on any failure so it can gate automation.
     */
    protected function verifyFleet(string $cluster, array $tasks, string $target): int
    {
        $failures = 0;
        $uuids = [];
        $rows = [];

        foreach ($tasks as $task) {
            foreach (static::verifyChecks($task['group'], $target) as $label => [$command, $pattern]) {
                $output = spin(
                    fn (): ?string => $this->exec($cluster, $task['arn'], $task['group'], $command),
                    sprintf('[verify] %s %s · %s', $task['group'], $task['id'], $label),
                );

                $passed = $output !== null && preg_match($pattern, $output) === 1;
                $failures += $passed ? 0 : 1;
                $rows[] = [$task['group'], $task['id'], $label, $passed ? '<fg=green>pass</>' : '<fg=red>FAIL</>'];

                if ($label === 'live query answered' && ($uuid = static::parseServerUuid($output)) !== null) {
                    $uuids[] = $uuid;
                }
            }
        }

        $distinct = count(array_unique($uuids));
        $splitBrainClean = $distinct === 1 && count($uuids) === count($tasks);
        $failures += $splitBrainClean ? 0 : 1;
        $rows[] = ['fleet', '—', sprintf('one @@server_uuid across %d task(s)', count($tasks)), $splitBrainClean ? '<fg=green>pass</>' : '<fg=red>FAIL</>'];

        if (is_string($url = $this->option('url')) && $url !== '') {
            $status = spin(fn (): ?string => static::probeUrl($url), sprintf('[probe] %s', $url));
            $passed = $status === '200';
            $failures += $passed ? 0 : 1;
            $rows[] = ['site', '—', sprintf('%s → %s', $url, $status ?? 'no response'), $passed ? '<fg=green>pass</>' : '<fg=red>FAIL</>'];
        }

        table(['Group', 'Task', 'Check', 'Result'], $rows);

        if ($failures > 0) {
            error(sprintf('%d verification failure(s) — do not consider the cutover complete.', $failures));

            return self::FAILURE;
        }

        note(sprintf('All clear — every container is on %s with a single server identity.', $target));
        warning(sprintf('The flip is TRANSIENT: set `database:` in yolo.yml to the new database\'s bare identifier (not the endpoint), `yolo env:push %1$s` the new DB_HOST, and `yolo deploy %1$s` promptly — any replaced task boots the old host until the image carries the change.', Helpers::environment()));

        return self::SUCCESS;
    }

    /**
     * The target endpoint: `--host` (endpoint used as-is, or an instance
     * identifier resolved with a describe) or an interactive picker over the
     * account's DB instances, with a manual-entry escape hatch. Whatever the
     * source, the value must look like a hostname — it's interpolated into a
     * sed running inside the container, so anything else hard-fails here.
     */
    protected function resolveTargetHost(): ?string
    {
        $host = $this->option('host');

        if (! is_string($host) || $host === '') {
            if (! $this->input->isInteractive()) {
                error('No target host — pass --host=<endpoint-or-instance-identifier> when running non-interactively.');

                return null;
            }

            $instances = Rds::instanceEndpoints();

            $choice = (string) select(
                label: 'Which database is the target?',
                options: [
                    ...collect($instances)->mapWithKeys(fn (string $endpoint, string $identifier): array => [$endpoint => sprintf('%s (%s)', $identifier, $endpoint)])->all(),
                    self::MANUAL_ENTRY => 'Enter an endpoint manually...',
                ],
                scroll: 10,
            );

            $host = $choice === self::MANUAL_ENTRY
                ? text('Target database endpoint', required: true)
                : $choice;
        }

        if (! str_ends_with($host, '.rds.amazonaws.com')) {
            try {
                $endpoint = Rds::instance($host)['Endpoint']['Address'] ?? null;
            } catch (RdsException $exception) {
                error(sprintf('Could not resolve the endpoint for "%s": %s.', $host, $exception->getAwsErrorCode() ?? 'unknown error'));

                return null;
            }

            if ($endpoint === null) {
                error(sprintf('Could not resolve the endpoint for "%s" — no matching DB instance.', $host));

                return null;
            }

            $host = $endpoint;
        }

        if (! static::validHost($host)) {
            error(sprintf('"%s" is not a valid hostname.', $host));

            return null;
        }

        return $host;
    }

    /**
     * Every running task across the flip's groups, tagged with its group (the
     * container name — the task-def names its container after the role).
     *
     * @return array<int, array{group: string, arn: string, id: string}>
     */
    protected function gatherTasks(string $cluster): array
    {
        $tasks = [];

        foreach (self::GROUPS as $group) {
            foreach (Ecs::runningTasks($cluster, Helpers::keyedResourceName($group, exclusive: true)) as $arn) {
                $tasks[] = ['group' => $group, 'arn' => $arn, 'id' => Str::afterLast($arn, '/')];
            }
        }

        return $tasks;
    }

    /**
     * Run a shell line in a container over ECS Exec and return its cleaned
     * output, or null when the session itself failed. ECS Exec doesn't
     * propagate the remote exit code, so callers judge success by output
     * shape (the verify patterns), never by code.
     */
    protected function exec(string $cluster, string $taskArn, string $container, string $shell): ?string
    {
        $process = new Process(
            RunCommand::executeCommandArgs($cluster, $taskArn, static::containerCommand($shell), $container, Manifest::get('region'), Helpers::keyedEnv('AWS_PROFILE')),
            timeout: 300,
        );

        return $process->run() === 0
            ? static::cleanOutput($process->getOutput())
            : null;
    }

    /**
     * Wrap a shell line for the ECS Exec agent: the agent tokenises the
     * command string shell-style, so the wire format is `/bin/sh -c "..."`
     * with inner double quotes escaped — the exact shape the field-proven
     * prototype used.
     */
    public static function containerCommand(string $shell): string
    {
        return sprintf('/bin/sh -c "%s"', str_replace('"', '\"', $shell));
    }

    /** Strip the SSM session banner/footer lines from exec output. */
    public static function cleanOutput(string $raw): string
    {
        $lines = array_filter(
            explode("\n", $raw),
            fn (string $line): bool => trim($line) !== '' && preg_match('/session/i', $line) !== 1,
        );

        return implode("\n", $lines);
    }

    /** The in-container patch: rewrite DB_HOST, then rebuild the cached config the booted app reads. */
    public static function patchCommand(string $target): string
    {
        return sprintf("sed -i 's|^DB_HOST=.*|DB_HOST=%s|' .env && php artisan optimize", $target);
    }

    public static function parseEnvHost(?string $output): ?string
    {
        return $output !== null && preg_match('/^DB_HOST=(\S+)/m', $output, $matches) === 1
            ? $matches[1]
            : null;
    }

    public static function parseServerUuid(?string $output): ?string
    {
        return $output !== null && preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $output, $matches) === 1
            ? $matches[0]
            : null;
    }

    public static function validHost(string $host): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/', $host) === 1;
    }

    /**
     * The plan the operator confirms: every task, its current host, and what
     * the flip will do to it.
     *
     * @param  array<int, array{group: string, arn: string, id: string, host: ?string}>  $tasks
     * @return array<int, array<int, string>>
     */
    public static function planRows(array $tasks, string $target): array
    {
        return array_map(fn (array $task): array => [
            $task['group'],
            $task['id'],
            $task['host'] ?? '(unreadable)',
            $task['host'] === $target ? 'already on target — skip' : sprintf('flip → %s', $target),
        ], $tasks);
    }

    /**
     * The verification layers per group: label => [in-container command,
     * pass pattern]. Each layer is independent — the `.env` line can be right
     * while the cached config is stale, and both can be right while the
     * connection still resolves to the old server.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function verifyChecks(string $group, string $target): array
    {
        $checks = [
            '.env DB_HOST' => ["grep '^DB_HOST=' .env", sprintf('/DB_HOST=%s/', preg_quote($target, '/'))],
            'cached config host' => ['php artisan config:show database.connections.mysql.host', sprintf('/%s/', preg_quote($target, '/'))],
            'live query answered' => ['php artisan tinker --execute="echo DB::scalar(\'select @@server_uuid\');"', '/[0-9a-f]{8}-/'],
            'maintenance mode off' => ['php artisan about --only=environment', '/OFF/'],
        ];

        if ($group === 'queue') {
            $checks['queue workers running'] = ["ps ax | grep -c 'queue:wor[k]'", '/[1-9]/'];
        }

        return $checks;
    }

    /** The site probe: the public URL's HTTP status code, or null when unreachable. */
    protected static function probeUrl(string $url): ?string
    {
        $process = new Process(['curl', '-s', '-o', '/dev/null', '-m', '15', '-w', '%{http_code}', $url]);

        return $process->run() === 0 ? trim($process->getOutput()) : null;
    }
}
