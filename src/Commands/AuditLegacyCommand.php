<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Audit\LegacyAudit;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Aws\ResourceGroupsTaggingApi;
use Codinglabs\Yolo\Resources\Fargate\TargetGroup;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Resources\Fargate\LoadBalancer;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class AuditLegacyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('audit:legacy')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of a table')
            ->setDescription('Report alpha-era (EC2/ASG) resources still tagged in the account, with cost estimates');
    }

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $region = Manifest::get('aws.region');

        // Every YOLO-managed resource carries the yolo:environment baseline tag,
        // so it's the one filter that catches the whole stack — both eras — for
        // this environment. Classification then sorts legacy from current.
        $tagged = ResourceGroupsTaggingApi::getResources([
            ['Key' => 'yolo:environment', 'Values' => [$environment]],
        ]);

        $instances = Ec2::instances(LegacyAudit::ec2InstanceIds($tagged));

        $report = LegacyAudit::report(
            taggedResources: $tagged,
            excludedNames: [(new LoadBalancer())->name(), (new TargetGroup())->name()],
            instances: $instances,
            region: $region,
        );

        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        return $this->render($report, $environment);
    }

    /**
     * @param  array{resources: array<int, array<string, mixed>>, totalMonthlyCost: float, unpricedCount: int}  $report
     */
    protected function render(array $report, string $environment): int
    {
        if (empty($report['resources'])) {
            info(sprintf("No alpha-era resources tagged for '%s' — nothing left on the legacy stack. 🐥", $environment));

            return self::SUCCESS;
        }

        warning(sprintf("Found %d alpha-era resource(s) still tagged for '%s':", count($report['resources']), $environment));

        table(
            ['Type', 'Name', 'Detail', 'Est. $/mo'],
            collect($report['resources'])
                ->map(fn (array $resource) => [
                    $resource['label'],
                    $resource['name'],
                    $resource['detail'] ?: '—',
                    static::money($resource['monthlyCost']),
                ])
                ->all(),
        );

        $summary = sprintf('Estimated total: $%s/mo', number_format($report['totalMonthlyCost'], 2));

        if ($report['unpricedCount'] > 0) {
            $summary .= sprintf(' (+ %d unpriced)', $report['unpricedCount']);
        }

        note($summary);
        note('Estimates use approximate on-demand list pricing — a guide, not a billing source.');

        return self::SUCCESS;
    }

    protected static function money(?float $value): string
    {
        return match (true) {
            $value === null => '?',
            $value <= 0.0 => '—',
            default => '$' . number_format($value, 2),
        };
    }
}
