<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use RuntimeException;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ecr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecr\TypesenseRepository;

/**
 * Conditionally builds and pushes the environment's Typesense image: the
 * pinned upstream base plus a config file carrying the admin key and the
 * static Raft peer list. The content tag is version + key fingerprint, so the
 * build is skipped entirely while neither has changed — and the plan pass
 * reports WOULD_BUILD Docker-free (an ECR tag lookup, never a daemon call).
 * Secret control = env-bucket S3 read + ECR pull; the task definition carries
 * no secret and DescribeTaskDefinition reveals nothing.
 *
 * Teardown is a skip: the repository's force-delete (the previous step) takes
 * the images with it.
 */
class BuildTypesenseImageStep implements LongRunning, Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (Lifecycle::state(Service::TYPESENSE) !== ServiceState::Provision) {
            return StepResult::SKIPPED;
        }

        $tag = Typesense::imageTag();

        if ($tag !== null && Ecr::imageExists((new TypesenseRepository())->name(), $tag)) {
            return StepResult::SYNCED;
        }

        // On a first plan the admin key hasn't been generated yet, so the tag
        // is unknowable — report the pending build; by apply the key step has
        // run and the tag resolves.
        $this->recordChange(Change::make('typesense image', 'absent', $tag ?? 'version + generated key'));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_BUILD;
        }

        $tag = Typesense::imageTag();

        if ($tag === null) {
            throw new RuntimeException('Cannot build the Typesense image — services.typesense.version and the generated admin key must both exist by now.');
        }

        $this->build((new TypesenseRepository())->uri(), $tag);

        return StepResult::BUILT;
    }

    public function patienceMessage(): string
    {
        return 'Building and pushing the Typesense image — usually 1-3 minutes.';
    }

    protected function build(string $repository, string $tag): void
    {
        $buildDirectory = Paths::yolo('build-typesense');

        try {
            $this->writeBuildContext($buildDirectory);
            $this->loginToEcr();

            // The nodes run arm64 (Typesense ships multi-arch; Graviton is the
            // default node platform), so the image is built for it explicitly.
            (new Process(
                command: [
                    'docker', 'build',
                    '--platform', 'linux/arm64',
                    '--tag', "$repository:$tag",
                    $buildDirectory,
                ],
                env: ['DOCKER_BUILDKIT' => '1'],
                timeout: null,
            ))->mustRun();

            (new Process(['docker', 'push', "$repository:$tag"], timeout: null))->mustRun();
        } finally {
            // The build context holds the admin key — shred it, success or not.
            $this->purge($buildDirectory);
        }
    }

    /**
     * The whole build context: a FROM+COPY Dockerfile, the server config with
     * the baked admin key, and the static peer list (host:peering:api per
     * node — identical on every node; each identifies itself by matching a
     * local interface address).
     */
    protected function writeBuildContext(string $directory): void
    {
        $this->purge($directory);

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create $directory");
        }

        file_put_contents($directory . '/Dockerfile', implode("\n", [
            sprintf('FROM typesense/typesense:%s', Typesense::version()),
            'COPY typesense-server.ini /etc/typesense/typesense-server.ini',
            'COPY nodes /etc/typesense/nodes',
            'CMD ["--config=/etc/typesense/typesense-server.ini"]',
            '',
        ]));

        file_put_contents($directory . '/typesense-server.ini', implode("\n", [
            '[server]',
            'api-address = 0.0.0.0',
            sprintf('api-port = %d', Typesense::API_PORT),
            sprintf('peering-port = %d', Typesense::PEERING_PORT),
            'data-dir = /tmp',
            sprintf('api-key = %s', Typesense::adminKey()),
            'nodes = /etc/typesense/nodes',
            '',
        ]));

        file_put_contents($directory . '/nodes', implode(',', Typesense::peers()) . "\n");
    }

    protected function loginToEcr(): void
    {
        $token = Aws::ecr()->getAuthorizationToken()['authorizationData'][0];
        [, $password] = explode(':', base64_decode((string) $token['authorizationToken']), 2);

        $login = new Process(
            command: [
                'docker', 'login',
                '--username', 'AWS',
                '--password-stdin',
                sprintf('%s.dkr.ecr.%s.amazonaws.com', Aws::accountId(), Manifest::get('region')),
            ],
            timeout: 60,
        );

        $login->setInput($password);
        $login->mustRun();
    }

    protected function purge(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    }
}
