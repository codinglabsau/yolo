<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Codinglabs\Yolo\Contracts\ReadsEnvironment;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\table;

/**
 * The environment's database assignment map: every app that has published a
 * claim file (`apps/{app}.yml` in the env config bucket) and the `database:`
 * its manifest declares. The fleet-level answer to "who is on which
 * database?" during a migration — read the declared truth here, prove the
 * live truth per app with `db:cutover --verify`. Read-only over S3 alone;
 * an app that has never synced or deployed since claims began carrying the
 * full manifest shows `—` until it republishes.
 */
class DbStatusCommand extends Command implements ReadOnlyCommand, ReadsEnvironment
{
    protected function configure(): void
    {
        $this
            ->setName('db:status')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the map as JSON and exit (machine-readable; for the /yolo skill and scripts)')
            ->setDescription("Show every environment app's declared database, from the published claim files");
    }

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $databases = $this->databasesByApp();

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'environment' => $environment,
                'apps' => $databases,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($databases === []) {
            info(sprintf("No published app claims in '%s' — nothing has synced or deployed here yet.", $environment));

            return self::SUCCESS;
        }

        intro(sprintf('yolo db:status · %s', $environment));

        table(['App', 'Database'], collect($databases)
            ->map(fn (?string $database, string $app): array => [$app, $database ?? '—'])
            ->values()
            ->all());

        return self::SUCCESS;
    }

    /**
     * app => declared database (endpoint or instance identifier), from every
     * claim under apps/ in the env config bucket. Null when the claim
     * predates full-manifest publishing or the app declares no database. A
     * missing bucket reads as an unprovisioned environment (no rows), not an
     * error — this is a status surface.
     *
     * @return array<string, string|null>
     */
    protected function databasesByApp(): array
    {
        $databases = [];
        $token = null;

        try {
            do {
                $result = Aws::s3()->listObjectsV2(array_filter([
                    'Bucket' => Paths::s3EnvConfigBucket(),
                    'Prefix' => 'apps/',
                    'ContinuationToken' => $token,
                ]));

                foreach ($result['Contents'] ?? [] as $object) {
                    if (! str_ends_with((string) $object['Key'], '.yml')) {
                        continue;
                    }

                    $claim = Yaml::parse((string) Aws::s3()->getObject([
                        'Bucket' => Paths::s3EnvConfigBucket(),
                        'Key' => (string) $object['Key'],
                    ])['Body']);

                    $app = is_array($claim) && is_string($claim['name'] ?? null)
                        ? $claim['name']
                        : Str::of((string) $object['Key'])->after('apps/')->before('.yml')->toString();

                    $database = is_array($claim) && is_string($claim['database'] ?? null) && $claim['database'] !== ''
                        ? $claim['database']
                        : null;

                    $databases[$app] = $database;
                }

                $token = ($result['IsTruncated'] ?? false) ? ($result['NextContinuationToken'] ?? null) : null;
            } while ($token !== null);
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return [];
            }

            throw $e;
        }

        ksort($databases);

        return $databases;
    }
}
