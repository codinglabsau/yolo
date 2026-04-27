<?php

namespace Codinglabs\Yolo\Commands;

use Carbon\Carbon;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class DeployStatusCommand extends Command
{
    use UsesCodeDeploy;

    protected function configure(): void
    {
        $this
            ->setName('deploy:status')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch deployments until they complete')
            ->setDescription('Show the status of in-progress and recent deployments');
    }

    public function handle(): int
    {
        $rows = [];

        do {
            $rows = $this->getDeploymentRows();

            table(
                ['Group', 'Deployment ID', 'Status', 'Instances', 'Version', 'Started', 'Completed'],
                $rows
            );

            if (! $this->option('watch')) {
                break;
            }

            $hasActive = collect($rows)->contains(
                fn ($row) => str_contains($row[2], 'In Progress')
                    || str_contains($row[2], 'Queued')
                    || str_contains($row[2], 'Baking')
            );

            if (! $hasActive) {
                info('All deployments have completed.');

                break;
            }

            sleep(5);
            $this->output->write("\033[2J\033[H");
        } while (true);

        $hasFailure = collect($rows)->contains(
            fn ($row) => str_contains($row[2], 'Failed')
                || str_contains($row[2], 'Stopped')
        );

        return $hasFailure ? 1 : 0;
    }

    protected function getDeploymentRows(): array
    {
        $rows = [];

        foreach (ServerGroup::cases() as $group) {
            $groupName = Helpers::keyedResourceName($group);

            try {
                $result = Aws::codeDeploy()->listDeployments([
                    'applicationName' => static::applicationName(),
                    'deploymentGroupName' => $groupName,
                ]);
            } catch (\Exception) {
                $rows[] = $this->emptyRow(strtoupper($group->value), '<fg=yellow>NOT FOUND</>');

                continue;
            }

            $deploymentIds = array_slice($result['deployments'], 0, 1);

            if (empty($deploymentIds)) {
                $rows[] = $this->emptyRow(strtoupper($group->value), '<fg=yellow>NO DEPLOYMENTS</>');

                continue;
            }

            $details = Aws::codeDeploy()->batchGetDeployments([
                'deploymentIds' => $deploymentIds,
            ]);

            foreach ($details['deploymentsInfo'] as $deployment) {
                $rows[] = [
                    strtoupper($group->value),
                    $deployment['deploymentId'],
                    $this->formatStatus($deployment['status']),
                    $this->formatInstanceSummary($deployment['deploymentOverview'] ?? []),
                    $this->extractVersion($deployment['description'] ?? ''),
                    Carbon::parse($deployment['createTime'])
                        ->tz('Australia/Brisbane')
                        ->format('d/m H:i:s'),
                    isset($deployment['completeTime'])
                        ? Carbon::parse($deployment['completeTime'])
                            ->tz('Australia/Brisbane')
                            ->format('d/m H:i:s')
                        : '-',
                ];
            }
        }

        return $rows;
    }

    protected function formatStatus(string $status): string
    {
        return match ($status) {
            'Succeeded' => '<fg=green>Succeeded</>',
            'Ready' => '<fg=green>Ready</>',
            'InProgress' => '<fg=blue>In Progress</>',
            'Created', 'Queued' => '<fg=yellow>Queued</>',
            'Baking' => '<fg=yellow>Baking</>',
            'Failed' => '<fg=red>Failed</>',
            'Stopped' => '<fg=red>Stopped</>',
            default => $status,
        };
    }

    protected function formatInstanceSummary(array $overview): string
    {
        if (empty($overview)) {
            return '-';
        }

        $parts = [];

        if ($count = ($overview['Succeeded'] ?? 0)) {
            $parts[] = sprintf('<fg=green>%d succeeded</>', $count);
        }

        if ($count = ($overview['InProgress'] ?? 0)) {
            $parts[] = sprintf('<fg=blue>%d in progress</>', $count);
        }

        if ($count = ($overview['Pending'] ?? 0)) {
            $parts[] = sprintf('<fg=yellow>%d pending</>', $count);
        }

        if ($count = ($overview['Failed'] ?? 0)) {
            $parts[] = sprintf('<fg=red>%d failed</>', $count);
        }

        if ($count = ($overview['Skipped'] ?? 0)) {
            $parts[] = sprintf('%d skipped', $count);
        }

        return implode(', ', $parts) ?: '-';
    }

    protected function extractVersion(string $description): string
    {
        if (preg_match('/Version (.+?) deployed/', $description, $matches)) {
            return $matches[1];
        }

        return '-';
    }

    protected function emptyRow(string $group, string $status): array
    {
        return [$group, '-', $status, '-', '-', '-', '-'];
    }
}
