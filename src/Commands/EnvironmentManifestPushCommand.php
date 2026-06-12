<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws\S3;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\EnvManifest;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Services\Lifecycle;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Concerns\ManagesEnvironmentFiles;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

class EnvironmentManifestPushCommand extends Command
{
    use ManagesEnvironmentFiles;

    protected function configure(): void
    {
        $this
            ->setName('environment:manifest:push')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('Upload the environment manifest (yolo-environment-{environment}.yml) to the env config bucket');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');
        $path = EnvManifest::localPath();

        if (! file_exists($path)) {
            error(sprintf('Could not find %s — pull it first with `yolo environment:manifest:pull %s`.', EnvManifest::filename(), $environment));

            return;
        }

        // Validate before anything touches the bucket — a misshapen manifest
        // must never become the environment's declared truth.
        try {
            $new = EnvManifest::parse((string) file_get_contents($path));
        } catch (IntegrityCheckException $e) {
            error($e->getMessage());

            return;
        }

        $current = [];

        try {
            $current = EnvManifest::parse($this->remote(EnvManifest::filename()));
        } catch (S3Exception $e) {
            if (! S3::isNotFound($e)) {
                throw $e;
            }

            warning(sprintf('%s does not exist in the env config bucket yet.', EnvManifest::filename()));
        } catch (IntegrityCheckException $e) {
            // The bucket copy carries keys this binary doesn't know — pushed
            // by a newer YOLO release. Overwriting it from here would silently
            // drop those declarations.
            error($e->getMessage() . ' The bucket manifest was likely written by a newer YOLO release — update codinglabsau/yolo before pushing.');

            return;
        }

        if (! $this->ensureRemovedOffersUnclaimed($current, $new)) {
            return;
        }

        if (! $this->confirmDifferences($this->dot($current), $this->dot($new), EnvManifest::filename())) {
            return;
        }

        $this->upload(EnvManifest::filename(), (string) file_get_contents($path), EnvManifest::filename());

        $this->confirmDeleteLocal($path, EnvManifest::filename());
    }

    /**
     * Removing a service offer is only accepted once no live app claims the
     * service — push-time is the cheapest failure point, and a hard error
     * naming the claimant beats a sync that tears infrastructure out from
     * under a running app. A live app with no published claim file blocks
     * removal the same way (unknown state ≠ no claims): its next deploy or
     * sync:app populates the registry.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $new
     */
    protected function ensureRemovedOffersUnclaimed(array $current, array $new): bool
    {
        foreach (Service::cases() as $service) {
            $path = 'services.' . $service->value;
            if (! Arr::has($current, $path)) {
                continue;
            }
            if (Arr::has($new, $path)) {
                continue;
            }

            if (($claimants = Lifecycle::activeClaimants($service)) !== []) {
                error(sprintf(
                    "Can't remove services.%s — %s still claim%s it. Drop the claim from each app's yolo.yml and deploy (or `yolo sync:app`) it first.",
                    $service->value,
                    implode(', ', $claimants),
                    count($claimants) === 1 ? 's' : '',
                ));

                return false;
            }

            if (($unpublished = Lifecycle::unpublishedLiveApps()) !== []) {
                error(sprintf(
                    "Can't remove services.%s — %s %s not published a claim file yet, so the claim set is unknowable. Deploy (or `yolo sync:app`) each first.",
                    $service->value,
                    implode(', ', $unpublished),
                    count($unpublished) === 1 ? 'has' : 'have',
                ));

                return false;
            }
        }

        return true;
    }
}
