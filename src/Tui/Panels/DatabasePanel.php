<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

use Codinglabs\Yolo\Arn;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Chart;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\ConsoleUrl;
use Codinglabs\Yolo\Tui\Viewport;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The app's database at a glance — the RDS instance/cluster declared by the
 * manifest `database:` key, with CPU, connections, freeable memory and read
 * latency over the last hour. YOLO doesn't manage the database; it reads the
 * identifier from the manifest — never the app's secret `.env`, which the observer
 * tier this panel runs under can't read anyway — so the tab is empty until a
 * database is declared. Read-only.
 */
class DatabasePanel implements Panel
{
    public const WINDOW_MINUTES = 60;

    public const CHART_HEIGHT = 5;

    /** @var array{cpu: array<int, float>, connections: array<int, float>, memory: array<int, float>, readLatency: array<int, float>, writeLatency: array<int, float>} */
    private const array EMPTY_SERIES = ['cpu' => [], 'connections' => [], 'memory' => [], 'readLatency' => [], 'writeLatency' => []];

    /** @var array{identifier: string, cluster: bool}|null */
    protected ?array $target = null;

    /** Why the declared database couldn't be classified, when it couldn't. */
    protected ?string $unresolvable = null;

    /** @var array{cpu: array<int, float>, connections: array<int, float>, memory: array<int, float>, readLatency: array<int, float>, writeLatency: array<int, float>} */
    protected array $series = self::EMPTY_SERIES;

    protected int $bodyHeight = 0;

    public function __construct(protected Viewport $viewport = new Viewport(followTail: false)) {}

    public function title(): string
    {
        return 'Database';
    }

    public function hotkey(): string
    {
        return 'b';
    }

    public function gather(): void
    {
        $this->series = self::EMPTY_SERIES;
        $this->unresolvable = null;

        try {
            $this->target = Rds::target();
        } catch (ResourceDoesNotExistException) {
            $this->target = null;
            $this->unresolvable = sprintf('"%s" matches no RDS cluster or instance in this account/region.', (string) Manifest::database());

            return;
        } catch (RdsException $exception) {
            $this->target = null;
            $this->unresolvable = sprintf('Could not classify "%s" (%s).', (string) Manifest::database(), $exception->getAwsErrorCode() ?? 'unknown error');

            return;
        }

        if ($this->target === null) {
            return;
        }

        $lookback = self::WINDOW_MINUTES * 60;
        $dimensions = self::dimensions($this->target);

        $this->series = [
            'cpu' => CloudWatch::metricSeries('AWS/RDS', 'CPUUtilization', $dimensions, 'Average', 60, $lookback),
            'connections' => CloudWatch::metricSeries('AWS/RDS', 'DatabaseConnections', $dimensions, 'Average', 60, $lookback),
            'memory' => CloudWatch::metricSeries('AWS/RDS', 'FreeableMemory', $dimensions, 'Average', 60, $lookback),
            'readLatency' => CloudWatch::metricSeries('AWS/RDS', 'ReadLatency', $dimensions, 'Average', 60, $lookback),
            'writeLatency' => CloudWatch::metricSeries('AWS/RDS', 'WriteLatency', $dimensions, 'Average', 60, $lookback),
        ];
    }

    public function render(int $width, int $height): array
    {
        $target = $this->target;

        if ($this->unresolvable !== null) {
            return [Theme::Warning->fg('  ' . $this->unresolvable)];
        }

        if ($target === null) {
            return [Theme::Muted->fg('  No database declared — set `database:` in yolo.yml to chart RDS metrics.')];
        }

        $header = [...self::details($target), ''];
        $footer = ['', Theme::Muted->fg('  ' . Helpers::truncate(self::consoleUrl($target), max(0, $width - 2)))];

        $this->bodyHeight = max(0, $height - count($header) - count($footer));

        return [...$header, ...$this->viewport->window(self::charts($this->series, $width), $this->bodyHeight), ...$footer];
    }

    /**
     * The identifier / kind summary. Pure.
     *
     * @param  array{identifier: string, cluster: bool}  $target
     * @return array<int, string>
     */
    public static function details(array $target): array
    {
        return [
            Theme::Primary->bold('  database') . Theme::Muted->fg('  ' . ($target['cluster'] ? 'Aurora cluster' : 'instance')),
            Theme::Muted->fg('  id        ') . Theme::Text->fg($target['identifier']),
        ];
    }

    /**
     * The four metric charts (CPU, connections, freeable memory, read latency),
     * converting bytes→MB and seconds→ms. Pure — pinned in a test with hand-built
     * series, no AWS.
     *
     * @param  array{cpu: array<int, float>, connections: array<int, float>, memory: array<int, float>, readLatency: array<int, float>, writeLatency: array<int, float>}  $series
     * @return array<int, string>
     */
    public static function charts(array $series, int $width): array
    {
        $caption = 'last ' . self::WINDOW_MINUTES . 'm';
        $memoryMb = array_map(static fn (float $bytes): float => $bytes / 1048576, $series['memory']);
        $readMs = array_map(static fn (float $seconds): float => $seconds * 1000, $series['readLatency']);

        return [
            ...Chart::render('CPU', $series['cpu'], $width, self::CHART_HEIGHT, 0, 100, '%', $caption, Theme::Primary),
            '',
            ...Chart::render('Connections', $series['connections'], $width, self::CHART_HEIGHT, 0, Chart::ceiling($series['connections']), '', $caption, Theme::Healthy),
            '',
            ...Chart::render('Freeable memory', $memoryMb, $width, self::CHART_HEIGHT, 0, Chart::ceiling($memoryMb), 'MB', $caption, Theme::Accent),
            '',
            ...Chart::render('Read latency', $readMs, $width, self::CHART_HEIGHT, 0, Chart::ceiling($readMs), 'ms', $caption, Theme::Warning),
            '',
        ];
    }

    /**
     * @param  array{identifier: string, cluster: bool}  $target
     * @return array<int, array{Name: string, Value: string}>
     */
    protected static function dimensions(array $target): array
    {
        return $target['cluster']
            ? [['Name' => 'DBClusterIdentifier', 'Value' => $target['identifier']], ['Name' => 'Role', 'Value' => 'WRITER']]
            : [['Name' => 'DBInstanceIdentifier', 'Value' => $target['identifier']]];
    }

    /**
     * A best-effort RDS console link, built from the resolved identifier (YOLO has
     * no RDS ARN of its own — it doesn't provision the database).
     *
     * @param  array{identifier: string, cluster: bool}  $target
     */
    protected static function consoleUrl(array $target): string
    {
        $arn = sprintf(
            'arn:aws:rds:%s:%s:%s:%s',
            (string) Manifest::get('region'),
            Aws::accountId(),
            $target['cluster'] ? 'cluster' : 'db',
            $target['identifier'],
        );

        return ConsoleUrl::for(Arn::parse($arn)) ?? '';
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
