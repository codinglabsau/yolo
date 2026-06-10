<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\EnvManifest;
use Aws\S3\Exception\S3Exception;
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
            ->setDescription('Upload the environment manifest (yolo-{environment}.yml) to the env config bucket');
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
        } catch (S3Exception) {
            warning(sprintf('%s does not exist in the env config bucket yet.', EnvManifest::filename()));
        }

        if (! $this->confirmDifferences($this->dot($current), $this->dot($new), EnvManifest::filename())) {
            return;
        }

        $this->upload(EnvManifest::filename(), (string) file_get_contents($path), EnvManifest::filename());
    }
}
