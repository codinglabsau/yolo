<?php

namespace Codinglabs\Yolo\Tui\Panels;

use Codinglabs\Yolo\Arn;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Chart;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\ConsoleUrl;
use Codinglabs\Yolo\Tui\Columns;
use Codinglabs\Yolo\Tui\Viewport;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Commands\ServicesCommand;
use Codinglabs\Yolo\Resources\Ecs\ServicesCluster;

/**
 * The service gate as a read-only tab — the same offered · used by · state table
 * the `yolo services` command shows, themed. When Typesense is offered it also
 * shows the cluster's live CPU / memory (the only env-backed service with its own
 * Fargate fleet). Managing the gate (add/edit/remove) is `yolo services`; here
 * it's status only.
 */
class ServicesPanel implements Panel
{
    /** @var array<int, array<string, mixed>> */
    protected array $rows = [];

    /** @var array{version: string|null, nodes: int, cpu: int, memory: int, quorum: int, cpuSeries: array<int, float>, memorySeries: array<int, float>, cluster: string}|null */
    protected ?array $typesense = null;

    protected int $bodyHeight = 0;

    public function __construct(protected Viewport $viewport = new Viewport(followTail: false)) {}

    public function title(): string
    {
        return 'Services';
    }

    public function hotkey(): string
    {
        return 's';
    }

    public function gather(): void
    {
        $this->rows = ServicesCommand::rows();
        $this->typesense = $this->offersTypesense() ? $this->gatherTypesense() : null;
    }

    public function render(int $width, int $height): array
    {
        $lines = [Columns::row([
            ['service', 14, Theme::Muted],
            ['offered', 22, Theme::Muted],
            ['used by', 22, Theme::Muted],
            ['state', 10, Theme::Muted],
        ])];

        foreach ($this->rows as $row) {
            $offered = $row['offered']
                ? ServicesCommand::offerSummary($row['offer'])
                : ($row['envBacked'] ? '—' : 'app-side');

            $usedBy = $row['usedBy'] === [] ? '—' : implode(', ', $row['usedBy']);

            $lines[] = Columns::row([
                [$row['service'], 14, Theme::Text],
                [$offered, 22, $row['offered'] ? Theme::Primary : Theme::Muted],
                [$usedBy, 22, $usedBy === '—' ? Theme::Muted : Theme::Text],
                [$row['state'], 10, self::stateTheme($row['state'])],
            ]);
        }

        if ($this->typesense !== null) {
            $lines = [
                ...$lines,
                ...self::typesenseBlock($this->typesense, $width),
                '',
                Theme::Muted->fg('  ' . self::consoleUrl($this->typesense['cluster'])),
            ];
        }

        $this->bodyHeight = $height;

        return $this->viewport->window($lines, $this->bodyHeight);
    }

    /**
     * The Typesense cluster detail (sizing from the env manifest) and its live
     * CPU / memory charts. Pure — pinned in a test with hand-built data, no AWS.
     *
     * @param  array{version: string|null, nodes: int, cpu: int, memory: int, quorum: int, cpuSeries: array<int, float>, memorySeries: array<int, float>, cluster: string}  $typesense
     * @return array<int, string>
     */
    public static function typesenseBlock(array $typesense, int $width): array
    {
        $detail = sprintf(
            'v%s · %d nodes · %du/%dMB per node · quorum %d',
            $typesense['version'] ?? '—',
            $typesense['nodes'],
            $typesense['cpu'],
            $typesense['memory'],
            $typesense['quorum'],
        );

        return [
            '',
            Theme::Primary->bold('  typesense cluster') . Theme::Muted->fg('  ' . $detail),
            '',
            ...Chart::render('CPU', $typesense['cpuSeries'], $width, 5, 0, 100, '%', 'last 60m', Theme::Primary),
            '',
            ...Chart::render('Memory', $typesense['memorySeries'], $width, 5, 0, 100, '%', 'last 60m', Theme::Accent),
        ];
    }

    public function hints(): array
    {
        return $this->typesense === null ? [] : ['↑↓ scroll'];
    }

    public function onKey(string $key): void
    {
        match ($key) {
            'up' => $this->viewport->scrollUp(),
            'down' => $this->viewport->scrollDown(),
            'pageup' => $this->viewport->pageUp($this->bodyHeight),
            'pagedown' => $this->viewport->pageDown($this->bodyHeight),
            'home' => $this->viewport->toTop(),
            'end' => $this->viewport->toTail(),
            default => null,
        };
    }

    /** The accent colour for a lifecycle state in the table. */
    public static function stateTheme(string $state): Theme
    {
        return match ($state) {
            'provision' => Theme::Healthy,
            'conflict', 'teardown' => Theme::Danger,
            'retain' => Theme::Warning,
            'app-side' => Theme::Accent,
            default => Theme::Muted,
        };
    }

    /** Is Typesense currently offered in this environment? */
    protected function offersTypesense(): bool
    {
        foreach ($this->rows as $row) {
            if (($row['service'] ?? null) === 'typesense' && ($row['offered'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Typesense sizing from the env manifest plus the cluster's last-hour CPU /
     * memory (the Fargate cluster aggregate; empty series when not yet provisioned).
     *
     * @return array{version: string|null, nodes: int, cpu: int, memory: int, quorum: int, cpuSeries: array<int, float>, memorySeries: array<int, float>, cluster: string}
     */
    protected function gatherTypesense(): array
    {
        $cluster = (new ServicesCluster())->name();
        $dimensions = [['Name' => 'ClusterName', 'Value' => $cluster]];

        return [
            'version' => Typesense::version(),
            'nodes' => Typesense::nodes(),
            'cpu' => Typesense::cpu(),
            'memory' => Typesense::memory(),
            'quorum' => Typesense::quorumFloor(),
            'cpuSeries' => CloudWatch::metricSeries('AWS/ECS', 'CPUUtilization', $dimensions, 'Average', 60, 3600),
            'memorySeries' => CloudWatch::metricSeries('AWS/ECS', 'MemoryUtilization', $dimensions, 'Average', 60, 3600),
            'cluster' => $cluster,
        ];
    }

    /** A best-effort console link to the Typesense ECS cluster. */
    protected static function consoleUrl(string $cluster): string
    {
        $arn = sprintf('arn:aws:ecs:%s:%s:cluster/%s', (string) Manifest::get('region'), Aws::accountId(), $cluster);

        return ConsoleUrl::for(Arn::parse($arn)) ?? '';
    }
}
