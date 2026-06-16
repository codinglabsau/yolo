<?php

namespace Codinglabs\Yolo\Commands;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\EnvManifest;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Services\Lifecycle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Concerns\ManagesEnvironmentFiles;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\error;
use function Laravel\Prompts\table;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * The two-key service gate, made visible and editable. A table of every service
 * — whether the environment offers it (`services.{name}` in the env manifest),
 * which running apps claim it, and the resulting lifecycle state — with add /
 * edit / remove driven generically off each service's offerKeys()/validateOffer()
 * (no per-service code here). Editing writes the env manifest and uploads it; the
 * next `yolo sync:environment` reconciles AWS to it.
 *
 * Add/edit/remove guard the same invariant `environment:manifest:push` does — a
 * service can't be withdrawn while a running app still claims it. App-side-only
 * services (rekognition, mediaconvert) are listed but not offerable.
 *
 *   yolo services production                                   # interactive
 *   yolo services production --json                            # read state (agents/CI)
 *   yolo services production --add=typesense --set version=29.0 --set nodes=3
 *   yolo services production --remove=typesense
 */
class ServicesCommand extends Command
{
    use ManagesEnvironmentFiles;

    protected function configure(): void
    {
        $this
            ->setName('services')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the service state as JSON and exit (no prompts)')
            ->addOption('add', null, InputOption::VALUE_REQUIRED, 'Offer a service non-interactively (pair with --set)')
            ->addOption('remove', null, InputOption::VALUE_REQUIRED, 'Withdraw a service offer non-interactively')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'key=value offer field for --add (repeatable)')
            ->setDescription('View and manage the services an environment offers');
    }

    public function handle(): int
    {
        if ($this->option('add') !== null) {
            return $this->applyAdd((string) $this->option('add'));
        }

        if ($this->option('remove') !== null) {
            return $this->applyRemove((string) $this->option('remove'), interactive: false);
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode(static::rows(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->interactive();
    }

    /**
     * The full service state — the data behind both the table and `--json`, and
     * reused by the Services dashboard tab. Reads the env manifest (offered) and the
     * published-claim registry (used by); the display state is derived so the
     * manual-edit "conflict" case surfaces as a row rather than throwing.
     *
     * @return array<int, array{service: string, description: string, envBacked: bool, offered: bool, offer: array<string, mixed>|null, offerKeys: array<int, string>, usedBy: array<int, string>, usesApp: bool, state: string}>
     */
    public static function rows(): array
    {
        $unpublished = Lifecycle::unpublishedLiveApps() !== [];

        return array_map(function (Service $service) use ($unpublished): array {
            $definition = $service->definition();
            $envBacked = $definition->envBacked();
            $offered = $envBacked && EnvManifest::has($service->envManifestKey());
            $usedBy = Lifecycle::liveAppsUsing($service);

            return [
                'service' => $service->value,
                'description' => $definition->description(),
                'envBacked' => $envBacked,
                'offered' => $offered,
                'offer' => $offered ? (array) EnvManifest::get($service->envManifestKey(), []) : null,
                'offerKeys' => $definition->offerKeys(),
                'usedBy' => $usedBy,
                'usesApp' => Manifest::usesService($service),
                'state' => self::displayState($envBacked, $offered, $usedBy !== [], $unpublished),
            ];
        }, Service::cases());
    }

    /**
     * The lifecycle verdict for the table: the two-key gate, plus the safe-to-show
     * variants for app-side-only services and the offer-removed-while-used conflict
     * (which Lifecycle::state() throws on — here it's just a flag).
     */
    public static function displayState(bool $envBacked, bool $offered, bool $used, bool $unpublished): string
    {
        return match (true) {
            ! $envBacked => 'app-side',
            ! $offered && $used => 'conflict',
            $offered && $used => 'provision',
            ! $offered => 'off',
            default => $unpublished ? 'retain' : 'teardown',
        };
    }

    /**
     * One-line summary of an offer for the table — `version=29.0 nodes=2`, or a
     * tick for an offer that carries no fields.
     *
     * @param  array<string, mixed>|null  $offer
     */
    public static function offerSummary(?array $offer): string
    {
        if ($offer === null || $offer === []) {
            return '✓';
        }

        return implode(' ', array_map(static fn (mixed $value, string $key): string => "$key=$value", $offer, array_keys($offer)));
    }

    protected function interactive(): int
    {
        while (true) {
            $rows = static::rows();

            table(['Service', 'Description', 'Status'], array_map(static fn (array $row): array => [
                $row['service'],
                $row['description'],
                $row['usesApp'] ? 'enabled' : 'disabled',
            ], $rows));

            $choices = [];

            foreach ($rows as $row) {
                $choices[$row['service']] = $row['service'];
            }

            // Cancel sits last, like a normal menu (not a leading "Quit").
            $choices['__cancel__'] = 'Cancel';

            $pick = select(label: 'Manage which service?', options: $choices, scroll: 10);

            if ($pick === '__cancel__') {
                return self::SUCCESS;
            }

            $this->manage(Service::from($pick));
        }
    }

    protected function manage(Service $service): void
    {
        $enabled = Manifest::usesService($service);

        // App-side services (mediaconvert / rekognition) are a plain app claim —
        // enabling adds them to yolo.yml, a per-app IAM grant on the next sync.
        if (! $service->definition()->envBacked()) {
            $action = select(label: $service->value, options: [
                'toggle' => $enabled ? 'Disable for this app' : 'Enable for this app',
                'cancel' => 'Cancel',
            ]);

            if ($action === 'toggle') {
                $this->toggleClaim($service, $enabled);
            }

            return;
        }

        // Env-backed services (typesense / ivs) carry an env-shared offer (sizing)
        // in the environment manifest alongside the per-app claim.
        $action = select(label: $service->value, options: array_filter([
            'enable' => $enabled ? null : 'Enable for this app',
            'configure' => $enabled ? 'Reconfigure the environment offer (CPU / RAM / nodes)' : null,
            'disable' => $enabled ? 'Disable for this app' : null,
            'cancel' => 'Cancel',
        ]));

        match ($action) {
            'enable' => $this->enableEnvBacked($service),
            'configure' => $this->configureOffer($service),
            'disable' => $this->toggleClaim($service, true),
            default => null,
        };
    }

    /**
     * Enable or disable a service for THIS app — write its claim into the app's
     * yolo.yml services list, then offer to sync the change to AWS straight away.
     */
    protected function toggleClaim(Service $service, bool $enabled): void
    {
        $services = Manifest::services();

        $next = $enabled
            ? array_values(array_diff($services, [$service->value]))
            : array_values(array_unique([...$services, $service->value]));

        if (! Manifest::setServiceList($next)) {
            error(sprintf(
                "Couldn't edit yolo.yml automatically — set environments.%s.services to [%s] by hand, then run `yolo sync:app %s`.",
                $this->argument('environment'),
                implode(', ', $next),
                $this->argument('environment'),
            ));

            return;
        }

        info(sprintf('%s %s for this app.', $enabled ? 'Disabled' : 'Enabled', $service->value));

        $this->offerToSync();
    }

    /** Tell the operator to sync — and, if they want, run it right now. */
    protected function offerToSync(): void
    {
        $environment = $this->argument('environment');

        if (! confirm(label: sprintf('Run `yolo sync:app %s` now to apply?', $environment), default: true)) {
            note(sprintf('Run `yolo sync:app %s` when you\'re ready to apply the change.', $environment));

            return;
        }

        $this->getApplication()?->find('sync:app')->run(
            new ArrayInput(['environment' => $environment]),
            $this->output,
        );
    }

    /**
     * Enable an env-backed service for THIS app: claim it in yolo.yml, then walk
     * the operator through its environment offer (sizing) and how to apply it.
     */
    protected function enableEnvBacked(Service $service): void
    {
        $next = array_values(array_unique([...Manifest::services(), $service->value]));

        if (! Manifest::setServiceList($next)) {
            error(sprintf(
                "Couldn't edit yolo.yml automatically — add \"%s\" to environments.%s.services by hand, then run `yolo sync %s`.",
                $service->value,
                $this->argument('environment'),
                $this->argument('environment'),
            ));

            return;
        }

        info(sprintf("Enabled %s in this app's yolo.yml.", $service->value));

        $this->configureOffer($service);
    }

    /**
     * Configure an env-backed service's offer (its env-shared sizing) on a LOCAL
     * copy of the environment manifest — pulled fresh from the bucket when one
     * isn't already on disk. The result is written locally, never straight to the
     * bucket, so the operator reviews it and applies it explicitly via
     * environment:manifest:push + sync (or lets us run those now).
     */
    protected function configureOffer(Service $service): void
    {
        $local = EnvManifest::localPath();

        if (! file_exists($local)) {
            $this->download(EnvManifest::filename(), $local);   // false → no remote yet; start empty
        }

        $manifest = file_exists($local) ? (array) (Yaml::parse((string) file_get_contents($local)) ?? []) : [];
        $current = (array) Arr::get($manifest, $service->envManifestKey(), []);
        $defaults = $service->definition()->offerDefaults();

        $offer = [];

        foreach ($service->definition()->offerKeys() as $key) {
            $value = trim(text(label: $key, default: (string) ($current[$key] ?? $defaults[$key] ?? '')));

            if ($value !== '') {
                $offer[$key] = static::coerce($value);
            }
        }

        $manifest['services'] ??= [];
        $manifest['services'][$service->value] = $offer;

        try {
            EnvManifest::parse(Yaml::dump($manifest, 6, 2));
        } catch (IntegrityCheckException $exception) {
            error($exception->getMessage());

            return;
        }

        file_put_contents($local, Yaml::dump($manifest, 6, 2));

        info(sprintf('Wrote the %s offer (%s) to %s.', $service->value, static::offerSummary($offer), EnvManifest::filename()));

        if (($implications = $service->definition()->implications()) !== '') {
            warning($implications);
        }

        $this->offerToApply();
    }

    /**
     * Spell out how to apply an environment-manifest change — and offer to run
     * those steps now. Defaults to no: pushing + syncing provisions real, billed
     * infrastructure, so it stays an explicit opt-in.
     */
    protected function offerToApply(): void
    {
        $environment = $this->argument('environment');

        note(sprintf(
            "To apply:\n  1. Review this app's yolo.yml and %s.\n  2. `yolo environment:manifest:push %s`  — publish the environment manifest.\n  3. `yolo sync %s`  — provision the change.",
            EnvManifest::filename(),
            $environment,
            $environment,
        ));

        if (! confirm(label: sprintf('Apply now — push the manifest and run `yolo sync %s`?', $environment), default: false)) {
            return;
        }

        $this->getApplication()?->find('environment:manifest:push')->run(new ArrayInput(['environment' => $environment]), $this->output);
        $this->getApplication()?->find('sync')->run(new ArrayInput(['environment' => $environment]), $this->output);
    }

    protected function applyAdd(string $name): int
    {
        if (! ($service = $this->envBackedService($name)) instanceof Service) {
            return self::FAILURE;
        }

        $offer = [];

        foreach ($this->option('set') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                error(sprintf('--set expects key=value, got "%s".', $pair));

                return self::FAILURE;
            }

            [$key, $value] = explode('=', (string) $pair, 2);
            $offer[$key] = static::coerce($value);
        }

        try {
            $this->writeOffer($service, $offer);
        } catch (IntegrityCheckException $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        $this->output->writeln((string) json_encode(static::rows(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    protected function applyRemove(string $name, bool $interactive): int
    {
        if (! ($service = $this->envBackedService($name)) instanceof Service) {
            return self::FAILURE;
        }

        if (! EnvManifest::has($service->envManifestKey())) {
            error(sprintf('%s is not currently offered by %s.', $service->value, $this->argument('environment')));

            return self::FAILURE;
        }

        // Mirror environment:manifest:push — a service can't be withdrawn while a
        // running app still claims it, and can't be safely judged while an app
        // hasn't published what it uses.
        if (($using = Lifecycle::liveAppsUsing($service)) !== []) {
            error(sprintf('%s still %s the %s service — remove it from each app\'s yolo.yml and deploy first.', implode(', ', $using), count($using) === 1 ? 'uses' : 'use', $service->value));

            return self::FAILURE;
        }

        if (($unpublished = Lifecycle::unpublishedLiveApps()) !== []) {
            error(sprintf('%s %s not published what they use yet — deploy them before withdrawing a service.', implode(', ', $unpublished), count($unpublished) === 1 ? 'has' : 'have'));

            return self::FAILURE;
        }

        if ($interactive && ! confirm(label: sprintf('Withdraw the %s offer? It will be torn down on the next sync.', $service->value), default: false)) {
            info('Nothing changed.');

            return self::SUCCESS;
        }

        $this->writeOffer($service, null);

        info(sprintf('Withdrew %s. Run `yolo sync:environment %s` to tear it down.', $service->value, $this->argument('environment')));

        return self::SUCCESS;
    }

    /**
     * Write a service offer into the env manifest and upload it. A null offer
     * removes the service. Validation + upload live in uploadEnvManifest().
     *
     * @param  array<string, mixed>|null  $offer
     */
    protected function writeOffer(Service $service, ?array $offer): void
    {
        $manifest = EnvManifest::current();
        $manifest['services'] ??= [];

        if ($offer === null) {
            unset($manifest['services'][$service->value]);
        } else {
            $manifest['services'][$service->value] = $offer;
        }

        $this->uploadEnvManifest($manifest);
    }

    /**
     * Resolve a service name to an env-offerable Service, or surface why it isn't
     * one (unknown, or app-side-only) and return null.
     */
    protected function envBackedService(string $name): ?Service
    {
        $service = Service::tryFrom($name);

        if (! $service instanceof Service) {
            error(sprintf('Unknown service "%s". Known: %s.', $name, implode(', ', Service::values())));

            return null;
        }

        if (! $service->definition()->envBacked()) {
            error(sprintf('%s is app-side only — it is claimed in an app\'s yolo.yml, not offered at the environment tier.', $service->value));

            return null;
        }

        return $service;
    }

    /**
     * Coerce a pure-integer offer value to int (so counts like nodes=3 store
     * unquoted), leaving everything else — including dotted version tags like
     * 29.0 — as a string.
     */
    protected static function coerce(string $value): int|string
    {
        return ctype_digit($value) ? (int) $value : $value;
    }
}
