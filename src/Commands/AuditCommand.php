<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\DeployCheck;
use Codinglabs\Yolo\Audit\RdsInspection;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

/**
 * Top-level audit verb — the environment health check. It shows every
 * YOLO-tagged resource grouped by ownership scope (account → env → app) and,
 * unlike the scoped verbs, also runs the deeper probes: a whole-stack drift
 * check (`sync --check`) and the RDS deletion-protection / topology probe. Any
 * error finding — an unexpected resource, drift, or a database with deletion
 * protection off — exits non-zero; warnings never do. Mirrors how bare `sync`
 * orchestrates all three tiers; use `audit:environment` / `audit:app` to narrow
 * to one tier's inventory (no health probes).
 */
class AuditCommand extends AbstractAuditCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('audit')
            ->setDescription('Health-check an environment: audit YOLO-tagged resources (account → environment → app), check for drift, and verify RDS deletion protection');
    }

    protected function includes(array $resource): bool
    {
        return true;
    }

    protected function emptyFilterMessage(string $environment): string
    {
        if ($this->option('unexpected')) {
            return sprintf("No unexpected resources in '%s' — everything tagged is accounted for.", $environment);
        }

        return sprintf("Nothing tagged for '%s'.", $environment);
    }

    /**
     * The health probes that make bare `audit` a health check rather than a plain
     * inventory: the RDS deletion-protection / topology snapshot and the
     * whole-stack drift verdict. Both record findings (errors fail the run); both
     * return structured data for the JSON payload.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    protected function gatherHealth(string $environment, bool $render): array
    {
        return [
            'rds' => $this->inspectRds($render),
            'drift' => $this->checkDrift($environment, $render),
        ];
    }

    /**
     * Probe the manifest-declared database (instance or Aurora cluster). Deletion
     * protection OFF is an error — a single fat-fingered console delete could take
     * the app's data with it. An unreadable target is only a warning: we can't
     * assert protection is off, just that we couldn't confirm it's on. Topology
     * basics are informational. Returns the structured snapshot for `--json`, or
     * null when no `database:` is declared (nothing to check).
     *
     * @return array<string, mixed>|null
     */
    protected function inspectRds(bool $render): ?array
    {
        $rds = RdsInspection::inspect();

        if (! $rds instanceof RdsInspection) {
            return null;
        }

        if (! $rds->readable) {
            $this->recordWarning(sprintf(
                'Could not inspect the database "%s" (%s) — deletion protection unconfirmed.',
                $rds->identifier,
                $rds->reason,
            ));
        } elseif (! $rds->deletionProtectionEnabled()) {
            $this->recordError(sprintf(
                'RDS %s "%s" has deletion protection DISABLED — a single console delete can take the data with it.',
                $rds->kind(),
                $rds->identifier,
            ));
        }

        if ($render) {
            $this->renderRds($rds);
        }

        return [
            'identifier' => $rds->identifier,
            'kind' => $rds->kind(),
            'readable' => $rds->readable,
            'reason' => $rds->reason,
            'deletionProtection' => $rds->deletionProtection,
            'engine' => $rds->engine,
            'engineVersion' => $rds->engineVersion,
            'status' => $rds->status,
            'instanceClass' => $rds->instanceClass,
            'allocatedStorage' => $rds->allocatedStorage,
            'multiAz' => $rds->multiAz,
            'members' => $rds->members,
        ];
    }

    protected function renderRds(RdsInspection $rds): void
    {
        note(sprintf('Database: %s "%s"', ucfirst($rds->kind()), $rds->identifier));

        if (! $rds->readable) {
            // The recorded warning carries the why; nothing to tabulate.
            return;
        }

        $protection = $rds->deletionProtectionEnabled()
            ? '<fg=green>enabled</>'
            : '<fg=red;options=bold>DISABLED</>';

        $basics = collect($rds->basics())
            ->map(fn (string $value, string $label): array => [$label, $value])
            ->values()
            ->all();

        table(['Property', 'Value'], [
            ['Deletion protection', $protection],
            ...$basics,
        ]);

        if ($rds->members !== []) {
            table(
                ['Member', 'Role', 'Class', 'Tier'],
                array_map(static fn (array $member): array => [
                    $member['identifier'],
                    $member['role'] === 'writer' ? '<fg=cyan>writer</>' : 'reader',
                    $member['class'] ?? '—',
                    $member['promotionTier'] === null ? '—' : (string) $member['promotionTier'],
                ], $rds->members),
            );
        }
    }

    /**
     * Run the whole-stack `sync --check` (account → environment → app) in-process
     * to verdict drift, mirroring the deploy gate ({@see Steps\Deploy\EnsureInSyncStep}).
     * It inherits the audit's read-only Observer cap — no escalation, no MFA — and
     * runs inside {@see DeployCheck} so the admin-owned env-service reconcilers a
     * read tier can't see are skipped (they're `yolo sync`'s job; the audit can't
     * reconcile them anyway). Drift is an error; in human mode the buffered sync
     * plan is flushed so the operator sees WHICH resources drifted.
     *
     * @return array{clean: bool}
     */
    protected function checkDrift(string $environment, bool $render): array
    {
        $console = $this->output;
        $buffer = new BufferedOutput($console->getVerbosity(), $console->isDecorated());

        $command = new SyncCommand();
        $input = new ArrayInput([
            'environment' => $environment,
            '--check' => true,
            '--no-progress' => true,
        ], $command->getDefinition());
        $input->setInteractive(false);

        // sync renders through Laravel Prompts' own global output, not the command's
        // — point it at the buffer, then restore a fresh default afterwards.
        Prompt::setOutput($buffer);

        try {
            $clean = DeployCheck::during(fn (): int => $command->run($input, $buffer)) === SyncCommand::SUCCESS;
        } catch (\Throwable $exception) {
            // A plan crash (a step threw — e.g. an AWS read the observer tier can't
            // make) isn't a drift verdict. Flush the per-step detail the plan
            // buffered before the bare exception bubbles up, so the operator sees
            // which step failed rather than a context-free stack trace.
            if ($render) {
                $console->write($buffer->fetch());
            }

            throw $exception;
        } finally {
            Prompt::setOutput(new ConsoleOutput());
        }

        if ($clean) {
            if ($render) {
                info(sprintf('%s is in sync.', $environment));
            }

            return ['clean' => true];
        }

        if ($render) {
            $console->write($buffer->fetch());
        }

        $this->recordError(sprintf(
            '%s has drifted from its declared state — run `yolo sync %s` to reconcile.',
            $environment,
            $environment,
        ));

        return ['clean' => false];
    }
}
