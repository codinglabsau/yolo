<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

use Codinglabs\Yolo\Arn;
use Codinglabs\Yolo\Tui\Chart;
use Codinglabs\Yolo\Tui\Theme;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\ConsoleUrl;
use Codinglabs\Yolo\Tui\Viewport;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Aws\ElastiCache;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The environment's shared Valkey cache — engine CPU, memory usage, connections
 * and evictions over the last hour, plus its live status and endpoint. The cache
 * is env-shared and optional (web apps default to it), so the tab shows an empty
 * state when the environment has none. Read-only.
 */
class CachePanel implements Panel
{
    public const WINDOW_MINUTES = 60;

    public const CHART_HEIGHT = 5;

    /** @var array{cpu: array<int, float>, memory: array<int, float>, connections: array<int, float>, evictions: array<int, float>} */
    private const array EMPTY_SERIES = ['cpu' => [], 'memory' => [], 'connections' => [], 'evictions' => []];

    protected bool $provisioned = false;

    protected string $status = '';

    protected string $endpoint = '';

    protected string $arn = '';

    /** @var array{cpu: array<int, float>, memory: array<int, float>, connections: array<int, float>, evictions: array<int, float>} */
    protected array $series = self::EMPTY_SERIES;

    protected int $bodyHeight = 0;

    public function __construct(protected Viewport $viewport = new Viewport(followTail: false)) {}

    public function title(): string
    {
        return 'Cache';
    }

    public function hotkey(): string
    {
        return 'c';
    }

    public function gather(): void
    {
        $this->provisioned = false;
        $this->series = self::EMPTY_SERIES;

        try {
            $group = ElastiCache::replicationGroup((new CacheCluster())->name());
        } catch (ResourceDoesNotExistException|AwsException) {
            // Not provisioned, or a transient AWS error — show the empty state
            // rather than crashing the live poll loop. The metric reads below
            // already swallow AwsException; this keeps the describe call in step.
            return;
        }

        $this->provisioned = true;
        $this->status = (string) ($group['Status'] ?? '');
        $this->endpoint = (string) data_get($group, 'NodeGroups.0.PrimaryEndpoint.Address', '');
        $this->arn = (string) ($group['ARN'] ?? '');

        $members = $group['MemberClusters'] ?? [];
        $memberId = is_array($members) && $members !== [] ? (string) $members[0] : (new CacheCluster())->name() . '-001';

        $lookback = self::WINDOW_MINUTES * 60;
        $dimensions = [['Name' => 'CacheClusterId', 'Value' => $memberId]];

        $this->series = [
            'cpu' => CloudWatch::metricSeries('AWS/ElastiCache', 'EngineCPUUtilization', $dimensions, 'Average', 60, $lookback),
            'memory' => CloudWatch::metricSeries('AWS/ElastiCache', 'DatabaseMemoryUsagePercentage', $dimensions, 'Average', 60, $lookback),
            'connections' => CloudWatch::metricSeries('AWS/ElastiCache', 'CurrConnections', $dimensions, 'Average', 60, $lookback),
            'evictions' => CloudWatch::metricSeries('AWS/ElastiCache', 'Evictions', $dimensions, 'Sum', 60, $lookback),
        ];
    }

    public function render(int $width, int $height): array
    {
        if (! $this->provisioned) {
            return [Theme::Muted->fg('  No cache cluster in this environment.')];
        }

        $header = [...self::details($this->status, $this->endpoint), ''];
        $footer = ['', Theme::Muted->fg('  ' . (ConsoleUrl::for(Arn::parse($this->arn)) ?? ''))];

        $this->bodyHeight = max(0, $height - count($header) - count($footer));

        return [...$header, ...$this->viewport->window(self::charts($this->series, $width), $this->bodyHeight), ...$footer];
    }

    /**
     * The status / endpoint / engine / node-type summary. Pure.
     *
     * @return array<int, string>
     */
    public static function details(string $status, string $endpoint): array
    {
        $statusColour = $status === 'available' ? Theme::Healthy : Theme::Warning;

        return [
            Theme::Primary->bold('  cache') . '  ' . $statusColour->fg($status === '' ? 'unknown' : $status),
            Theme::Muted->fg('  endpoint  ') . Theme::Text->fg($endpoint === '' ? '—' : $endpoint),
            Theme::Muted->fg('  engine    ') . Theme::Text->fg(CacheCluster::ENGINE . ' ' . CacheCluster::ENGINE_VERSION . ' · ' . CacheCluster::NODE_TYPE),
        ];
    }

    /**
     * The four cache charts (engine CPU, memory usage, connections, evictions).
     * Pure — pinned in a test with hand-built series, no AWS.
     *
     * @param  array{cpu: array<int, float>, memory: array<int, float>, connections: array<int, float>, evictions: array<int, float>}  $series
     * @return array<int, string>
     */
    public static function charts(array $series, int $width): array
    {
        $caption = 'last ' . self::WINDOW_MINUTES . 'm';

        return [
            ...Chart::render('Engine CPU', $series['cpu'], $width, self::CHART_HEIGHT, 0, 100, '%', $caption, Theme::Primary),
            '',
            ...Chart::render('Memory used', $series['memory'], $width, self::CHART_HEIGHT, 0, 100, '%', $caption, Theme::Accent),
            '',
            ...Chart::render('Connections', $series['connections'], $width, self::CHART_HEIGHT, 0, Chart::ceiling($series['connections']), '', $caption, Theme::Healthy),
            '',
            ...Chart::render('Evictions', $series['evictions'], $width, self::CHART_HEIGHT, 0, Chart::ceiling($series['evictions']), '', $caption, Theme::Warning),
            '',
        ];
    }

    public function hints(): array
    {
        return ['↑↓ scroll'];
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
}
