<?php

namespace Codinglabs\Yolo\Commands;

use Carbon\Carbon;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Aws\Ecr;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * Roll an environment back to a previously-deployed app version — re-deploy an
 * image that already exists in ECR, skipping the build.
 *
 * Reuses the deploy tail (register a task-definition revision pinned to the
 * chosen version, re-run the deploy hooks, roll each service onto it, wait for
 * healthy, re-UPSERT DNS) but runs no build and re-pushes no assets — the image
 * and its asset tree already exist. Code and assets revert cleanly; the database
 * does not — `migrate` in the hooks is forward-only, so it applies nothing new
 * and never reverts the schema, which the confirm gate calls out before anything
 * changes.
 *
 * The picker lists the last deployments by **app version** (parsed from the
 * image ref), newest first — never by ECS task-def revision, which is just
 * AWS's per-family registration counter and says nothing about which version a
 * revision runs (sync-registered revisions even pin the moving `:latest` tag,
 * so they're filtered out as targets). `--app-version` skips the picker for CI;
 * `--force` skips the confirm.
 *
 *   yolo rollback production                                        # interactive picker
 *   yolo rollback production --app-version=26.24.2.0945 --force     # non-interactive / CI
 */
class RollbackCommand extends SteppedCommand
{
    use RendersServiceStatus;

    /**
     * The deploy tail, minus only the build-time work: the image and its assets
     * already exist, so no build and no PushAssetsToS3Step. The deploy hooks DO
     * re-run via ExecuteDeployStepsStep — they're what makes a version live
     * (cache rebuilds, migrate, etc.) and they run against the rolled-back
     * image. `migrate` is forward-only, so it applies nothing new and never
     * reverts the schema (hence the database warning on the confirm gate).
     *
     * @var array<int, class-string>
     */
    protected array $steps = [
        Steps\Deploy\RegisterTaskDefinitionRevisionStep::class,
        Steps\Deploy\ExecuteDeployStepsStep::class,
        Steps\Deploy\UpdateEcsServiceStep::class,
        Steps\Deploy\WaitForDeploymentHealthyStep::class,
        Steps\Deploy\SyncSoloRecordSetStep::class,
        Steps\Deploy\SyncMultitenancyRecordSetStep::class,
    ];

    /**
     * Tags that are never a rollback target — moving pointers that re-resolve
     * at launch, not a stable point-in-time image.
     */
    public const RESERVED_TAGS = ['latest', 'buildcache'];

    /** How many versions the picker shows per page. */
    public const PAGE_SIZE = 10;

    protected function configure(): void
    {
        $this
            ->setName('rollback')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app-version', null, InputOption::VALUE_REQUIRED, 'Roll back to this version non-interactively (skips the picker)')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Comma-separated service groups to roll (web,queue,scheduler) — defaults to all the app runs')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->addOption('no-progress', null, InputOption::VALUE_NONE, 'Hide the progress output')
            ->setDescription('Roll back to a previously-deployed version, without a build');
    }

    #[\Override]
    public function handle(): int
    {
        $version = $this->resolveTargetVersion();

        if ($version === null) {
            return self::SUCCESS;
        }

        if (! $this->confirmRollback($version)) {
            info('🐥 Nothing rolled back.');

            return self::SUCCESS;
        }

        // The deploy tail reads the image tag from the `app-version` option —
        // the same lever a tagged deploy pulls — so injecting the chosen
        // version pins RegisterTaskDefinitionRevisionStep's revision to it.
        $this->input->setOption('app-version', $version);

        $result = parent::handle();

        if ($result === self::SUCCESS) {
            $this->renderRollbackSummary($version);
        }

        return $result;
    }

    /**
     * Resolve the version to roll back to: an explicit `--app-version`
     * (validated against ECR) or the interactive picker. Returns null when
     * there's nothing to do — bad version, no candidates, or already on it —
     * with the reason surfaced to the operator.
     */
    protected function resolveTargetVersion(): ?string
    {
        $repository = (new EcrRepository())->name();

        if (($explicit = $this->option('app-version')) !== null) {
            if (! Ecr::imageExists($repository, $explicit)) {
                error(sprintf('Version "%s" was not found in the %s repository.', $explicit, $repository));

                return null;
            }

            return $this->unlessAlreadyRunning($explicit);
        }

        if (! $this->input->isInteractive()) {
            error('A non-interactive rollback needs --app-version=<version>.');

            return null;
        }

        $targets = static::rollbackTargets(Ecr::images($repository));

        if ($targets === []) {
            warning(sprintf('No previously-deployed versions found for %s.', $this->argument('environment')));

            return null;
        }

        return $this->unlessAlreadyRunning($this->pickVersion($targets));
    }

    /**
     * Guard against rolling back to the version that's already running — that's
     * a no-op, so say so and stop rather than churn a pointless revision.
     */
    protected function unlessAlreadyRunning(string $version): ?string
    {
        if ($version === $this->currentVersion()) {
            info(sprintf('%s is already running %s — nothing to roll back.', $this->argument('environment'), $version));

            return null;
        }

        return $version;
    }

    /**
     * Page through the rollback targets and let the operator pick one. The
     * first page holds the 10 most recent; "Show older versions →" walks back
     * through the rest (ECR keeps the last 30), newest always first.
     *
     * @param  array<int, array{version: string, pushedAt: int}>  $targets
     */
    protected function pickVersion(array $targets): string
    {
        $current = $this->currentVersion();
        $page = 0;
        $lastPage = (int) max(0, ceil(count($targets) / static::PAGE_SIZE) - 1);

        while (true) {
            $options = [];

            foreach (array_slice($targets, $page * static::PAGE_SIZE, static::PAGE_SIZE) as $target) {
                $options[$target['version']] = static::targetLabel($target, $current);
            }

            if ($page < $lastPage) {
                $options['__older__'] = 'Show older versions →';
            }

            if ($page > 0) {
                $options['__newer__'] = '← Back to newer versions';
            }

            $choice = (string) select(
                label: sprintf('Roll back %s to which version?', $this->argument('environment')),
                options: $options,
                scroll: 15,
            );

            if ($choice === '__older__') {
                $page++;

                continue;
            }

            if ($choice === '__newer__') {
                $page--;

                continue;
            }

            return $choice;
        }
    }

    /**
     * Reduce raw ECR image details to selectable rollback targets: one entry
     * per image carrying an explicit version tag (a stable, pinnable
     * point-in-time image), newest first. Images tagged only
     * `latest`/`buildcache` are dropped — moving pointers, never a safe pin.
     *
     * @param  array<int, array<string, mixed>>  $images
     * @return array<int, array{version: string, pushedAt: int}>
     */
    public static function rollbackTargets(array $images): array
    {
        return collect($images)
            ->map(function (array $image): ?array {
                $version = collect($image['imageTags'] ?? [])
                    ->reject(fn (string $tag): bool => in_array($tag, static::RESERVED_TAGS, true))
                    ->first();

                if ($version === null) {
                    return null;
                }

                return [
                    'version' => $version,
                    'pushedAt' => self::pushedAtTimestamp($image['imagePushedAt'] ?? null),
                ];
            })
            ->filter()
            ->sortByDesc('pushedAt')
            ->values()
            ->all();
    }

    /**
     * The picker label for a target — version, how long ago it was pushed, and
     * a "(current)" marker on the version that's running now.
     *
     * @param  array{version: string, pushedAt: int}  $target
     */
    public static function targetLabel(array $target, ?string $current): string
    {
        $label = sprintf(
            '%s  ·  pushed %s',
            $target['version'],
            Carbon::createFromTimestamp($target['pushedAt'])->diffForHumans(),
        );

        return $target['version'] === $current ? $label . '  (current)' : $label;
    }

    /**
     * The app version currently running, read from the live service's primary
     * task definition. Null when nothing is deployed yet.
     */
    protected function currentVersion(): ?string
    {
        $groups = Manifest::serverGroups();

        if ($groups === []) {
            return null;
        }

        try {
            $service = Ecs::service((new EcsCluster())->name(), (new EcsService($groups[0]))->name());
        } catch (ResourceDoesNotExistException) {
            return null;
        }

        $taskDefinitionArn = collect($service['deployments'] ?? [])->firstWhere('status', 'PRIMARY')['taskDefinition'] ?? null;

        if ($taskDefinitionArn === null) {
            return null;
        }

        try {
            $taskDefinition = Ecs::taskDefinition($taskDefinitionArn);
        } catch (ResourceDoesNotExistException) {
            return null;
        }

        return static::versionFromImage($taskDefinition['containerDefinitions'][0]['image'] ?? '');
    }

    /**
     * Spell out the database boundary, then gate. Code and assets are versioned
     * and immutable so they revert cleanly; the database does not — a rollback
     * past a destructive migration can break against the old code. The confirm
     * defaults to "no" and `--force` skips it (for CI, alongside
     * `--app-version`).
     */
    protected function confirmRollback(string $version): bool
    {
        $this->output->writeln('');
        $this->output->writeln('  <options=bold;fg=yellow>⚠ The database is not rolled back</>');
        $this->output->writeln('  <fg=yellow>Deploy hooks re-run, but migrations are forward-only — the schema is not reverted.</>');
        $this->output->writeln('  <fg=yellow>Destructive migrations since this version will NOT be undone; verify the old code runs against it.</>');
        $this->output->writeln('');

        if ($this->option('force')) {
            return true;
        }

        return confirm(
            label: sprintf('Roll back %s to %s?', $this->argument('environment'), $version),
            default: false,
        );
    }

    /**
     * Recap what's now running once the rollback has settled — the same summary
     * table and dashboard link `yolo status` and a deploy show, minus the live
     * deployment/load panels.
     */
    protected function renderRollbackSummary(string $version): void
    {
        intro(sprintf('Rolled back to %s', $version));

        foreach ($this->statusLines(static::gatherServiceStatuses(withLoad: false), time(), deployments: false, load: false) as $line) {
            $this->output->writeln($line);
        }
    }

    private static function pushedAtTimestamp(mixed $pushedAt): int
    {
        return match (true) {
            $pushedAt instanceof \DateTimeInterface => $pushedAt->getTimestamp(),
            is_int($pushedAt) => $pushedAt,
            is_string($pushedAt) && $pushedAt !== '' => Carbon::parse($pushedAt)->getTimestamp(),
            default => 0,
        };
    }
}
