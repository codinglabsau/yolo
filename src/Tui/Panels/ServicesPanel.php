<?php

namespace Codinglabs\Yolo\Tui\Panels;

use Closure;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\Tui\Columns;
use Codinglabs\Yolo\Commands\ServicesCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The two-key service gate as a tab — the same offered · used by · state table
 * the `yolo services` command shows, themed. ⏎ launches the full interactive
 * manager (it reuses ServicesCommand, so add/edit/remove and its guards live in
 * exactly one place).
 */
class ServicesPanel implements Panel
{
    /** @var array<int, array<string, mixed>> */
    protected array $rows = [];

    public function __construct(protected string $environment, protected OutputInterface $output) {}

    public function title(): string
    {
        return 'Services';
    }

    public function hotkey(): string
    {
        return 'v';
    }

    public function gather(): void
    {
        $this->rows = ServicesCommand::rows();
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

        return $lines;
    }

    public function hints(): array
    {
        return ['⏎ manage'];
    }

    public function onKey(string $key): ?Closure
    {
        return $key === 'enter' ? $this->manage(...) : null;
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

    /**
     * Launch the interactive services manager — the same flow as `yolo services`.
     *
     * @codeCoverageIgnore drives Laravel Prompts; verified by hand
     */
    protected function manage(): void
    {
        $command = new ServicesCommand();
        $command->input = new ArrayInput(['environment' => $this->environment], $command->getDefinition());
        $command->output = $this->output;
        $command->handle();
    }
}
