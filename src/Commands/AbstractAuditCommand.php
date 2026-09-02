<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Arn;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\ConsoleUrl;
use Codinglabs\Yolo\Audit\Audit;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Concerns\RecordsWarnings;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Codinglabs\Yolo\Contracts\ReadsEnvironment;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Aws\ResourceGroupsTaggingApi;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Query, classification and render are identical across the three audit verbs;
 * only the row filter and the empty-state message change.
 */
abstract class AbstractAuditCommand extends Command implements ReadOnlyCommand, ReadsEnvironment
{
    use RecordsWarnings;

    /**
     * Errors fail the run (exit 1); warnings never affect the exit code.
     *
     * @var array<int, string>
     */
    protected array $errors = [];

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('unexpected', null, InputOption::VALUE_NONE, 'Only show unexpected resources (anything not accounted for by YOLO)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the audit as JSON and exit (machine-readable; for the /yolo skill and scripts)');
    }

    public function handle(): int
    {
        $environment = $this->argument('environment');

        $tagged = ResourceGroupsTaggingApi::getResources([
            ['Key' => 'yolo:environment', 'Values' => [$environment]],
        ]);

        $report = Audit::classify($tagged, $this->liveApps($environment));

        $json = (bool) $this->option('json');

        $this->flagUnexpected($report);

        if (! $json) {
            $this->renderInventory($report, $environment);
        }

        $health = $this->gatherHealth($environment, render: ! $json);

        if ($json) {
            return $this->renderJson($report, $environment, $health);
        }

        return $this->concludeHealth();
    }

    /**
     * Health probes beyond the tag inventory; the scoped verbs stay inventory-only
     * and {@see AuditCommand} overrides. With $render false (JSON) the probes stay
     * silent and only the returned block and recorded findings carry through.
     *
     * @return array<string, mixed>
     */
    protected function gatherHealth(string $environment, bool $render): array
    {
        return [];
    }

    /**
     * --unexpected only narrows the table, never what counts against the verdict;
     * scope-aware so audit:app fails on that app's strays, not the environment's.
     *
     * @param  array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, okCount: int, unexpectedCount: int}  $report
     */
    protected function flagUnexpected(array $report): void
    {
        $unexpectedInScope = collect($report['resources'])
            ->filter(fn (array $resource): bool => $this->includes($resource))
            ->where('status', Audit::STATUS_UNEXPECTED)
            ->count();

        if ($unexpectedInScope > 0) {
            $this->recordError(sprintf(
                '%d resource(s) unexpected — not accounted for by YOLO. Check the Reason column before removing anything.',
                $unexpectedInScope,
            ));
        }
    }

    protected function recordError(string $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * Warnings then errors, so the most serious lands nearest the prompt.
     */
    protected function concludeHealth(): int
    {
        foreach ($this->recordedWarnings() as $warning) {
            warning($warning);
        }

        foreach ($this->errors as $error) {
            error($error);
        }

        return $this->errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Applied before the universal `--unexpected` filter, so subclasses never see it.
     *
     * @param  array<string, mixed>  $resource
     */
    abstract protected function includes(array $resource): bool;

    abstract protected function emptyFilterMessage(string $environment): string;

    /**
     * @return array<int, string>
     */
    protected function liveApps(string $environment): array
    {
        return Ecs::liveApps($environment);
    }

    /**
     * @param  array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, okCount: int, unexpectedCount: int}  $report
     */
    protected function renderInventory(array $report, string $environment): void
    {
        if (empty($report['resources'])) {
            info(sprintf("Nothing tagged for '%s'.", $environment));

            return;
        }

        note(sprintf('Live apps: %s', $report['liveApps'] ? implode(', ', $report['liveApps']) : 'none'));

        $rows = $this->filtered($report['resources']);

        if ($rows->isEmpty()) {
            info($this->emptyFilterMessage($environment));

            return;
        }

        table(
            ['Scope', 'Status', 'Type', 'Name', 'App', 'Reason'],
            $rows->map(fn (array $resource): array => [
                static::scopeLabel($resource['scope']),
                static::statusLabel($resource['status']),
                $resource['type'],
                static::nameCell($resource),
                $resource['app'] ?? '—',
                $resource['reason'] ?? '—',
            ])->all(),
        );

        note(sprintf(
            "%d tagged for '%s' · %d ok · %d unexpected",
            count($report['resources']),
            $environment,
            $report['okCount'],
            $report['unexpectedCount'],
        ));
    }

    /**
     * Counts derive from the filtered rows so the payload is internally consistent
     * (the human note prints env-wide totals alongside a filtered table).
     *
     * @param  array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, okCount: int, unexpectedCount: int}  $report
     * @param  array<string, mixed>  $health
     */
    protected function renderJson(array $report, string $environment, array $health = []): int
    {
        $rows = $this->filtered($report['resources']);

        $this->output->writeln((string) json_encode([
            'environment' => $environment,
            'liveApps' => array_values($report['liveApps']),
            'okCount' => $rows->where('status', Audit::STATUS_OK)->count(),
            'unexpectedCount' => $rows->where('status', Audit::STATUS_UNEXPECTED)->count(),
            'resources' => static::auditJsonRows($rows->all()),
            'health' => $health,
            'findings' => [
                'errors' => array_values($this->errors),
                'warnings' => array_values($this->recordedWarnings()),
            ],
            'healthy' => $this->errors === [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, array<string, mixed>>  $resources
     * @return array<int, array<string, mixed>>
     */
    public static function auditJsonRows(array $resources): array
    {
        return array_map(static fn (array $resource): array => [
            'scope' => $resource['scope'],
            'status' => $resource['status'],
            'type' => $resource['type'],
            'name' => $resource['name'],
            'app' => $resource['app'] ?? null,
            'reason' => $resource['reason'] ?? null,
            'arn' => $resource['arn'] ?? null,
        ], $resources);
    }

    /**
     * @param  array<int, array<string, mixed>>  $resources
     * @return Collection<int, array<string, mixed>>
     */
    protected function filtered(array $resources)
    {
        return collect($resources)
            ->filter(fn (array $resource): bool => $this->includes($resource))
            ->when($this->option('unexpected'), fn ($rows) => $rows->where('status', Audit::STATUS_UNEXPECTED))
            ->sortBy(fn (array $resource): string => Audit::orderKey($resource))
            ->values();
    }

    protected static function statusLabel(string $status): string
    {
        return match ($status) {
            Audit::STATUS_OK => '<fg=green>ok</>',
            default => '<fg=yellow;options=bold>unexpected</>',
        };
    }

    protected static function scopeLabel(string $scope): string
    {
        return match ($scope) {
            Audit::SCOPE_ACCOUNT => '<fg=magenta>account</>',
            Audit::SCOPE_ENV => '<fg=cyan>environment</>',
            default => '<fg=blue>app</>',
        };
    }

    /**
     * OSC 8 hyperlink to the Console page; terminals without hyperlink support
     * just show the text.
     *
     * @param  array<string, mixed>  $resource
     */
    protected static function nameCell(array $resource): string
    {
        $url = ConsoleUrl::for(Arn::parse($resource['arn']));

        return $url === null
            ? $resource['name']
            : sprintf('<href=%s>%s</>', $url, $resource['name']);
    }
}
