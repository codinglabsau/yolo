<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Audit\Arn;
use Codinglabs\Yolo\Audit\Audit;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Audit\ConsoleUrl;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Aws\ResourceGroupsTaggingApi;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Shared scaffolding for the `audit`, `audit:environment` and `audit:app`
 * commands. The audit verbs are scope-grouped to mirror sync — bare `audit`
 * orchestrates everything, the scope-specific verbs narrow to one tier — so
 * the query (tag-key filter on `yolo:environment`), classification and table
 * render are identical across all three; only the row filter and the
 * empty-state message change.
 */
abstract class AbstractAuditCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('drift', null, InputOption::VALUE_NONE, 'Only show drift (resources tagged for an app that is no longer live)');
    }

    public function handle(): int
    {
        $environment = $this->argument('environment');

        $tagged = ResourceGroupsTaggingApi::getResources([
            ['Key' => 'yolo:environment', 'Values' => [$environment]],
        ]);

        $report = Audit::classify($tagged, $this->liveApps($environment));

        return $this->render($report, $environment);
    }

    /**
     * Per-subcommand row filter. Return true to include the row in the table.
     * Applied before the universal `--drift` filter, so subclasses don't need
     * to know about `--drift` at all.
     *
     * @param  array<string, mixed>  $resource
     */
    abstract protected function includes(array $resource): bool;

    /**
     * Shown when the post-filter list is empty. Subclasses tailor the wording
     * to their scope so `--drift` on a scope that can't carry drift reads
     * clearly rather than as a non sequitur.
     */
    abstract protected function emptyFilterMessage(string $environment): string;

    /**
     * Apps with at least one running Fargate task — the authoritative "what's
     * actually deployed" signal. We only probe clusters in this environment's
     * namespace so the audit doesn't list tasks across unrelated clusters.
     *
     * @return array<int, string>
     */
    protected function liveApps(string $environment): array
    {
        $prefix = "yolo-$environment-";

        $liveClusters = collect(Ecs::clusterArns())
            ->filter(fn (string $arn) => str_starts_with(Arn::parse($arn)?->resourceId ?? '', $prefix))
            ->filter(fn (string $arn) => Ecs::clusterRunningTasks($arn) !== [])
            ->all();

        return Audit::appsFromClusters($liveClusters, $environment);
    }

    /**
     * @param  array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, okCount: int, driftCount: int, rogueCount: int}  $report
     */
    protected function render(array $report, string $environment): int
    {
        if (empty($report['resources'])) {
            info(sprintf("Nothing tagged for '%s'. 🐥", $environment));

            return self::SUCCESS;
        }

        note(sprintf('Live apps: %s', $report['liveApps'] ? implode(', ', $report['liveApps']) : 'none'));

        $rows = $this->filtered($report['resources']);

        if ($rows->isEmpty()) {
            info($this->emptyFilterMessage($environment));

            return self::SUCCESS;
        }

        if (! $this->option('drift') && $report['driftCount'] > 0) {
            warning(sprintf('%d resource(s) are drift — tagged for an app that is no longer live.', $report['driftCount']));
        }

        if (! $this->option('drift') && $report['rogueCount'] > 0) {
            warning(sprintf('%d resource(s) are rogue — no YOLO ownership marker (`yolo:app` or `yolo:scope`).', $report['rogueCount']));
        }

        table(
            ['Scope', 'Status', 'Type', 'Name', 'App'],
            $rows->map(fn (array $resource) => [
                static::scopeLabel($resource['scope']),
                static::statusLabel($resource['status']),
                $resource['type'],
                static::nameCell($resource),
                $resource['app'] ?? '—',
            ])->all(),
        );

        note(sprintf(
            "%d tagged for '%s' · %d drift · %d rogue · %d ok",
            count($report['resources']),
            $environment,
            $report['driftCount'],
            $report['rogueCount'],
            $report['okCount'],
        ));

        return self::SUCCESS;
    }

    /**
     * Apply the subcommand's scope filter and the universal `--drift` flag,
     * then order by scope (account → env → app, top to bottom), drift first
     * within a scope, then app and name. Drift is still surfaced regardless
     * of position — via the warning line, the red label and `--drift`.
     *
     * @param  array<int, array<string, mixed>>  $resources
     * @return Collection<int, array<string, mixed>>
     */
    protected function filtered(array $resources)
    {
        return collect($resources)
            ->filter(fn (array $resource) => $this->includes($resource))
            ->when($this->option('drift'), fn ($rows) => $rows->where('status', Audit::STATUS_DRIFT))
            ->sortBy(fn (array $resource) => Audit::orderKey($resource))
            ->values();
    }

    protected static function statusLabel(string $status): string
    {
        return match ($status) {
            Audit::STATUS_DRIFT => '<fg=red;options=bold>DRIFT</>',
            Audit::STATUS_OK => '<fg=green>ok</>',
            default => '<fg=yellow>rogue</>',
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
     * The resource name, wrapped in an OSC 8 hyperlink to its AWS Console page
     * when we can build one. Terminals that support hyperlinks (Ghostty, Warp,
     * iTerm2) make the name clickable; the rest just show the text.
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
