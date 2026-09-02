<?php

namespace Codinglabs\Yolo\Commands;

use Carbon\Carbon;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Aws\Ecr;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Contracts\DeployerCommand;
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
 * Re-deploys an image already in ECR: the deploy tail with no build and no asset
 * push. Code and assets revert cleanly; the database does not — `migrate` in the
 * hooks is forward-only and never reverts the schema.
 *
 * Targets are listed by app version (parsed from the image ref), never by ECS
 * task-def revision — that's just AWS's per-family registration counter and says
 * nothing about which version a revision runs (sync-registered revisions even pin
 * the moving `:latest` tag).
 *
 *   yolo rollback production                                        # interactive picker
 *   yolo rollback production --app-version=26.24.2.0945 --force     # non-interactive / CI
 */
class RollbackCommand extends SteppedCommand implements DeployerCommand
{
    use RendersServiceStatus;

    /**
     * The deploy hooks DO re-run — they're what makes a version live (cache
     * rebuilds, migrate, etc.).
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
     * Moving pointers that re-resolve at launch — never a stable rollback target.
     */
    public const RESERVED_TAGS = ['latest', 'buildcache'];

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

        // The deploy tail reads the image tag from `app-version` — the same lever a
        // tagged deploy pulls.
        $this->input->setOption('app-version', $version);

        $result = parent::handle();

        if ($result === self::SUCCESS) {
            $this->renderRollbackSummary($version);
        }

        return $result;
    }

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

    protected function unlessAlreadyRunning(string $version): ?string
    {
        if ($version === $this->currentVersion()) {
            info(sprintf('%s is already running %s — nothing to roll back.', $this->argument('environment'), $version));

            return null;
        }

        return $version;
    }

    /**
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
