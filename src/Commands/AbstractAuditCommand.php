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
 * Shared scaffolding for the `audit`, `audit:environment` and `audit:app`
 * commands. The audit verbs are scope-grouped to mirror sync — bare `audit`
 * orchestrates everything, the scope-specific verbs narrow to one tier — so
 * the query (tag-key filter on `yolo:environment`), classification and table
 * render are identical across all three; only the row filter and the
 * empty-state message change.
 */
abstract class AbstractAuditCommand extends Command implements ReadOnlyCommand, ReadsEnvironment
{
    // Warnings reuse the step runner's deferred-warning buffer verbatim (record
    // now, render in one block at the end) — a non-stepped command, same pattern.
    use RecordsWarnings;

    /**
     * Health-check errors: anything that should fail the run (exit 1). Warnings
     * (recordWarning, above) never affect the exit code. The split is the whole
     * point of the audit-as-health-check exit contract.
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

        // Bare `audit` overrides gatherHealth to add the drift + RDS probes; the
        // scoped verbs (audit:environment / audit:app) keep inventory-only. Human
        // runs render the probe tables inline; JSON gathers the same data silently
        // for the payload. Either way the findings drive the exit code.
        $health = $this->gatherHealth($environment, render: ! $json);

        if ($json) {
            return $this->renderJson($report, $environment, $health);
        }

        return $this->concludeHealth();
    }

    /**
     * Health probes beyond the tag inventory. Returns a structured health block
     * for the JSON payload (empty by default). No-op for the scoped audit verbs —
     * they're focused inventory tools; {@see AuditCommand} overrides it to run the
     * whole-stack drift check and the RDS deletion-protection probe. When $render
     * is true the probes also print their human tables; when false (JSON) they
     * stay silent and only the returned block and recorded findings carry through.
     *
     * @return array<string, mixed>
     */
    protected function gatherHealth(string $environment, bool $render): array
    {
        return [];
    }

    /**
     * Record any unexpected resources in this command's scope as an error finding
     * — it fails the run regardless of --unexpected (which only narrows the table,
     * never what counts against the health verdict). Scope-aware so audit:app fails
     * on that app's strays, not the whole environment's.
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

    /**
     * Record a health-check error — a finding that fails the run (exit 1). Drift,
     * unexpected resources and RDS deletion-protection-off are all errors.
     */
    protected function recordError(string $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * Replay the buffered warnings and errors in one block (warnings then errors,
     * so the most serious lands last and nearest the prompt), then resolve the
     * exit code: FAILURE if anything errored, SUCCESS otherwise. Mirrors the step
     * runner's renderDeferredWarnings, with errors driving the code.
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
     * Render the tag-inventory table (the unexpected-resource finding is recorded
     * separately in flagUnexpected). Void — the exit code is resolved later in
     * concludeHealth() from the accumulated findings, not from this render.
     *
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
     * The machine-readable form for `--json` consumers (the `/yolo` skill,
     * scripts): the same scope-filtered + `--unexpected`-filtered rows the table
     * would show, plus the environment, live apps and counts derived from those
     * rows — so the payload is internally consistent (unlike the human note,
     * which prints the env-wide totals alongside a filtered table). The `health`
     * block carries the probe results (empty for the scoped verbs) and `findings`
     * the same errors/warnings the human run prints, so a consumer sees exactly
     * what drove the exit code.
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
