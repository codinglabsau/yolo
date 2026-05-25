<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Audit\Arn;
use Codinglabs\Yolo\Audit\Audit;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Aws\ResourceGroupsTaggingApi;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Read-only audit of the YOLO-tagged resources in an environment. Surfaces
 * "drift" — resources tagged for an app that no longer has a live Fargate
 * cluster — alongside the live and unattributed (shared / not-yet-stamped)
 * resources, so a cutover can confirm nothing's been left behind.
 */
class AuditCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('audit')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Only show resources attributed to this app')
            ->addOption('drift', null, InputOption::VALUE_NONE, 'Only show drift (resources tagged for an app that is no longer live)')
            ->setDescription('Audit YOLO-tagged resources for an environment and flag unexplained drift');
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
     * @param  array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, liveCount: int, driftCount: int, unattributedCount: int}  $report
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

        table(
            ['Status', 'Type', 'Name', 'App'],
            $rows->map(fn (array $resource) => [
                static::statusLabel($resource['status']),
                $resource['type'],
                $resource['name'],
                $resource['app'] ?? '—',
            ])->all(),
        );

        note(sprintf(
            "%d tagged for '%s' · %d drift · %d unattributed · %d live",
            count($report['resources']),
            $environment,
            $report['driftCount'],
            $report['unattributedCount'],
            $report['liveCount'],
        ));

        return self::SUCCESS;
    }

    /**
     * Apply the --app and --drift filters, then sort drift first so the thing
     * you most likely ran this for is at the top.
     *
     * @param  array<int, array<string, mixed>>  $resources
     * @return Collection<int, array<string, mixed>>
     */
    protected function filtered(array $resources)
    {
        $order = [Audit::STATUS_DRIFT => 0, Audit::STATUS_UNATTRIBUTED => 1, Audit::STATUS_LIVE => 2];

        return collect($resources)
            ->when($this->option('app'), fn ($rows, $app) => $rows->where('app', $app))
            ->when($this->option('drift'), fn ($rows) => $rows->where('status', Audit::STATUS_DRIFT))
            ->sortBy([
                fn (array $resource) => $order[$resource['status']],
                fn (array $resource) => $resource['app'] ?? '',
                fn (array $resource) => $resource['name'],
            ])
            ->values();
    }

    protected function emptyFilterMessage(string $environment): string
    {
        if ($this->option('drift')) {
            return sprintf("No drift in '%s' — every tagged resource maps to a live app. 🐥", $environment);
        }

        return sprintf("No resources tagged for app '%s' in '%s'.", $this->option('app'), $environment);
    }

    protected static function statusLabel(string $status): string
    {
        return $status === Audit::STATUS_DRIFT ? 'DRIFT' : $status;
    }
}
