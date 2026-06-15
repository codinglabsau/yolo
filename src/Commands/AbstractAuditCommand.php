<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Audit\Arn;
use Codinglabs\Yolo\Audit\Audit;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Audit\ConsoleUrl;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
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
abstract class AbstractAuditCommand extends Command implements ReadOnlyCommand
{
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

        if ($this->option('json')) {
            return $this->renderJson($report, $environment);
        }

        return $this->render($report, $environment);
    }

    /**
     * Per-subcommand row filter. Return true to include the row in the table.
     * Applied before the universal `--unexpected` filter, so subclasses don't
     * need to know about `--unexpected` at all.
     *
     * @param  array<string, mixed>  $resource
     */
    abstract protected function includes(array $resource): bool;

    /**
     * Shown when the post-filter list is empty. Subclasses tailor the wording to
     * their scope so `--unexpected` reads clearly when a scope happens to have
     * nothing unexpected.
     */
    abstract protected function emptyFilterMessage(string $environment): string;

    /**
     * Apps with at least one running Fargate task — the authoritative "what's
     * actually deployed" signal, shared with the service lifecycle's claim
     * gating via Ecs::liveApps().
     *
     * @return array<int, string>
     */
    protected function liveApps(string $environment): array
    {
        return Ecs::liveApps($environment);
    }

    /**
     * @param  array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, okCount: int, unexpectedCount: int}  $report
     */
    protected function render(array $report, string $environment): int
    {
        if (empty($report['resources'])) {
            info(sprintf("Nothing tagged for '%s'.", $environment));

            return self::SUCCESS;
        }

        note(sprintf('Live apps: %s', $report['liveApps'] ? implode(', ', $report['liveApps']) : 'none'));

        $rows = $this->filtered($report['resources']);

        if ($rows->isEmpty()) {
            info($this->emptyFilterMessage($environment));

            return self::SUCCESS;
        }

        if (! $this->option('unexpected') && $report['unexpectedCount'] > 0) {
            warning(sprintf('%d resource(s) are unexpected — not accounted for by YOLO. Check the Reason column before removing anything.', $report['unexpectedCount']));
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

        return self::SUCCESS;
    }

    /**
     * The machine-readable form for `--json` consumers (the `/yolo` skill,
     * scripts): the same scope-filtered + `--unexpected`-filtered rows the table
     * would show, plus the environment, live apps and counts derived from those
     * rows — so the payload is internally consistent (unlike the human note,
     * which prints the env-wide totals alongside a filtered table).
     *
     * @param  array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, okCount: int, unexpectedCount: int}  $report
     */
    protected function renderJson(array $report, string $environment): int
    {
        $rows = $this->filtered($report['resources']);

        $this->output->writeln((string) json_encode([
            'environment' => $environment,
            'liveApps' => array_values($report['liveApps']),
            'okCount' => $rows->where('status', Audit::STATUS_OK)->count(),
            'unexpectedCount' => $rows->where('status', Audit::STATUS_UNEXPECTED)->count(),
            'resources' => static::auditJsonRows($rows->all()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * Flatten audit resource rows to the clean machine shape (a stable subset of
     * keys, `app`/`reason`/`arn` defaulting to null). Pure — unit-tested directly
     * with hand-built rows, no AWS.
     *
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
     * Apply the subcommand's scope filter and the universal `--unexpected` flag,
     * then order by scope (account → env → app, top to bottom), unexpected first
     * within a scope, then by reason, app and name. Unexpected rows are still
     * surfaced regardless of position — via the warning line and the label.
     *
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
