<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

use Closure;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Tui\Columns;
use Codinglabs\Yolo\Commands\ServicesCommand;

/**
 * A read view of both manifests for the environment — the operator-owned env
 * manifest (domain + the service offers) and the app's own yolo.yml (name,
 * region, account, claimed services). Service offers are edited from the
 * Services tab; broader env-manifest edits go through `environment:manifest:pull
 * / push`. Read-only here by design — the destructive surface stays explicit.
 */
class ManifestPanel implements Panel
{
    /** @var array<string, mixed> */
    protected array $env = [];

    /** @var array<string, mixed> */
    protected array $app = [];

    public function title(): string
    {
        return 'Manifest';
    }

    public function hotkey(): string
    {
        return 'm';
    }

    public function gather(): void
    {
        $this->env = EnvManifest::current();
        $this->app = [
            'name' => Manifest::name(),
            'region' => Manifest::get('region'),
            'account-id' => Manifest::get('account-id'),
            'services' => Manifest::services(),
        ];
    }

    public function render(int $width): array
    {
        $lines = [
            Theme::Primary->bold('  Environment manifest') . Theme::Muted->fg('  ' . EnvManifest::filename()),
            Columns::row([['domain', 16, Theme::Muted], [(string) ($this->env['domain'] ?? '—'), 44, Theme::Text]]),
        ];

        $services = (array) ($this->env['services'] ?? []);

        if ($services === []) {
            $lines[] = Columns::row([['services', 16, Theme::Muted], ['none offered', 44, Theme::Muted]]);
        } else {
            foreach ($services as $name => $offer) {
                $lines[] = Columns::row([
                    ['services.' . $name, 16, Theme::Muted],
                    [ServicesCommand::offerSummary(is_array($offer) ? $offer : null), 44, Theme::Primary],
                ]);
            }
        }

        $claimed = $this->app['services'] === [] ? '—' : implode(', ', (array) $this->app['services']);

        $lines[] = '';
        $lines[] = Theme::Primary->bold('  App') . Theme::Muted->fg('  yolo.yml');
        $lines[] = Columns::row([['name', 16, Theme::Muted], [(string) $this->app['name'], 44, Theme::Text]]);
        $lines[] = Columns::row([['region', 16, Theme::Muted], [(string) ($this->app['region'] ?? '—'), 44, Theme::Text]]);
        $lines[] = Columns::row([['account-id', 16, Theme::Muted], [(string) ($this->app['account-id'] ?? '—'), 44, Theme::Text]]);
        $lines[] = Columns::row([['uses', 16, Theme::Muted], [$claimed, 44, $claimed === '—' ? Theme::Muted : Theme::Text]]);

        return $lines;
    }

    public function hints(): array
    {
        return ['offers: Services tab', 'env:manifest:pull/push'];
    }

    public function onKey(string $key): ?Closure
    {
        return null;
    }
}
