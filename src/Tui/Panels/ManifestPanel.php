<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Tui\Panels;

use Closure;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Theme;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Tui\Columns;
use Codinglabs\Yolo\Commands\ServicesCommand;
use Codinglabs\Yolo\Concerns\ManagesEnvironmentFiles;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\text;
use function Laravel\Prompts\error;

/**
 * A view of both manifests for the environment — the operator-owned env manifest
 * (domain + the service offers) and the app's own yolo.yml (name, region,
 * account, claimed services). The env `domain` is editable in place (`e`): a
 * Prompts modal validates and uploads the env manifest, applied on the next
 * sync:environment. Service offers are edited from the Services tab; the app
 * yolo.yml is repo-owned and shown read-only.
 */
class ManifestPanel implements Panel
{
    use ManagesEnvironmentFiles;

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

    public function render(int $width, int $height): array
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
        return ['e edit domain', 'offers → Services tab'];
    }

    public function onKey(string $key): ?Closure
    {
        return ($key === 'e' || $key === 'enter') ? $this->editDomain(...) : null;
    }

    /**
     * Edit the environment domain in place: a Prompts modal writes the value back
     * to the env manifest (validated + uploaded), applied on the next sync. An
     * empty value clears the domain. Reuses the one env-manifest write path so the
     * Services gate and this share identical validation.
     *
     * @codeCoverageIgnore drives Laravel Prompts + S3; verified by hand
     */
    protected function editDomain(): void
    {
        $value = trim(text(
            label: 'Environment domain',
            default: (string) (EnvManifest::get('domain') ?? ''),
            hint: 'Applied on the next yolo sync:environment',
        ));

        $manifest = EnvManifest::current();

        if ($value === '') {
            unset($manifest['domain']);
        } else {
            $manifest['domain'] = $value;
        }

        try {
            $this->uploadEnvManifest($manifest);
        } catch (IntegrityCheckException $exception) {
            error($exception->getMessage());

            return;
        }

        info(sprintf('Updated domain in %s. Run `yolo sync:environment` to apply.', EnvManifest::filename()));
    }
}
