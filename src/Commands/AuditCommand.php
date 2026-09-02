<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\DeployCheck;
use Codinglabs\Yolo\Audit\RdsInspection;
use Codinglabs\Yolo\Audit\RdsNetworkPosture;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

/**
 * The environment health check: unlike the scoped verbs it also runs the
 * whole-stack drift check and the RDS deletion-protection / topology probe.
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
     * Deletion protection OFF is an error; an unreadable target is only a warning
     * (we can't assert protection is off, just that we couldn't confirm it's on).
     * The managed/external classification is informational — an externally-hosted
     * database is a valid posture.
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

        $posture = RdsNetworkPosture::evaluate($rds);

        if ($posture instanceof RdsNetworkPosture) {
            if ($posture->publiclyAccessible === true) {
                $this->recordWarning(sprintf(
                    'RDS %s "%s" is PUBLICLY ACCESSIBLE — it has an internet-facing endpoint. Move it behind the private subnet tier (or disable public access) and reach it with `yolo db:tunnel`.',
                    $rds->kind(),
                    $rds->identifier,
                ));
            }

            if ($posture->taskIngress === false) {
                $this->recordWarning(sprintf(
                    'No attached security group on "%s" allows %d from the app\'s task security group — Fargate tasks may not be able to reach the database.',
                    $rds->identifier,
                    $rds->port,
                ));
            }
        }

        if ($render) {
            $this->renderRds($rds, $posture);
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
            'network' => $posture instanceof RdsNetworkPosture ? [
                'classification' => $posture->classification,
                'vpcId' => $posture->vpcId,
                'subnetGroup' => $rds->subnetGroupName,
                'securityGroups' => $rds->securityGroupIds,
                'publiclyAccessible' => $posture->publiclyAccessible,
                'taskIngress' => $posture->taskIngress,
            ] : null,
        ];
    }

    protected function renderRds(RdsInspection $rds, ?RdsNetworkPosture $posture): void
    {
        // An unreadable snapshot may not know its kind — "Database: Database" reads doubled.
        note($rds->readable
            ? sprintf('Database: %s "%s"', ucfirst($rds->kind()), $rds->identifier)
            : sprintf('Database: "%s"', $rds->identifier));

        if (! $rds->readable) {
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
            ...($posture instanceof RdsNetworkPosture ? $this->postureRows($rds, $posture) : []),
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
     * @return array<int, array{0: string, 1: string}>
     */
    protected function postureRows(RdsInspection $rds, RdsNetworkPosture $posture): array
    {
        $classification = match ($posture->classification) {
            RdsNetworkPosture::EXPOSED => '<fg=red;options=bold>EXPOSED</> (publicly accessible)',
            RdsNetworkPosture::MANAGED => '<fg=green>managed</> (private subnet tier)',
            RdsNetworkPosture::EXTERNAL => 'externally managed',
            default => 'unknown',
        };

        return array_filter([
            ['Network', $classification],
            $posture->vpcId === null ? null : ['VPC', $posture->vpcId],
            $rds->subnetGroupName === null ? null : ['Subnet group', $rds->subnetGroupName],
            $rds->securityGroupIds === [] ? null : ['Security groups', implode(', ', $rds->securityGroupIds)],
            [$rds->port === null ? 'Task ingress' : sprintf('Task ingress %d', $rds->port), match ($posture->taskIngress) {
                true => '<fg=green>yes</>',
                false => '<fg=yellow>none found</>',
                null => 'unknown',
            }],
        ]);
    }

    /**
     * Mirrors the deploy gate ({@see Steps\Deploy\EnsureInSyncStep}). Inherits the
     * audit's read-only Observer cap and runs inside {@see DeployCheck} so the
     * admin-owned env-service reconcilers a read tier can't see are skipped.
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

        // sync renders through Laravel Prompts' own global output, not the command's.
        Prompt::setOutput($buffer);

        try {
            $clean = DeployCheck::during(fn (): int => $command->run($input, $buffer)) === SyncCommand::SUCCESS;
        } catch (\Throwable $exception) {
            // A plan crash isn't a drift verdict — flush the buffered per-step detail
            // so the operator sees which step failed, not a context-free stack trace.
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
