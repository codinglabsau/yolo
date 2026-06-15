<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Aws\Ecs\EcsClient;
use Codinglabs\Yolo\Helpers;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The env-tier roll-up (`status:environment`) gathers each app's web service —
 * cluster/service names follow the yolo-{env}-{app} convention, so no per-app
 * manifest is needed — and renders a compact one-row-per-app table.
 */
function envRollupProbe(): object
{
    return new class(new BufferedOutput())
    {
        use RendersServiceStatus;

        public function __construct(public OutputInterface $output) {}

        /** @return array<string, mixed> */
        public function rollup(string $environment, string $app): array
        {
            return self::gatherAppRollup($environment, $app);
        }

        /**
         * @param  array<int, array<string, mixed>>  $rows
         * @return array<int, string>
         */
        public function lines(array $rows): array
        {
            return $this->envRollupLines($rows);
        }
    };
}

it('rolls up an app from its web service and task definition', function (): void {
    $mock = new MockHandler();
    $mock->append(new Result(['services' => [[
        'status' => 'ACTIVE',
        'runningCount' => 2,
        'desiredCount' => 2,
        'pendingCount' => 0,
        'launchType' => 'FARGATE',
        'deployments' => [[
            'status' => 'PRIMARY',
            'rolloutState' => 'COMPLETED',
            'taskDefinition' => 'arn:aws:ecs:ap-southeast-2:1234:task-definition/yolo-prod-shop-web:42',
        ]],
    ]]]));
    $mock->append(new Result(['taskDefinition' => [
        'cpu' => '512',
        'memory' => '1024',
        'containerDefinitions' => [['image' => '1234.dkr.ecr.ap-southeast-2.amazonaws.com/yolo-prod-shop:20260605-1']],
    ]]));

    Helpers::app()->instance('ecs', new EcsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));

    expect(envRollupProbe()->rollup('prod', 'shop'))->toMatchArray([
        'app' => 'shop',
        'exists' => true,
        'running' => 2,
        'desired' => 2,
        'rolloutState' => 'COMPLETED',
        'revision' => 'web:42',
        'version' => '20260605-1',
    ]);
});

it('marks an app whose web service is gone as not deployed', function (): void {
    $mock = new MockHandler();
    $mock->append(new AwsException('nope', new Command('DescribeServices'), ['code' => 'ServiceNotFoundException']));

    Helpers::app()->instance('ecs', new EcsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));

    expect(envRollupProbe()->rollup('prod', 'ghost'))->toMatchArray([
        'app' => 'ghost',
        'exists' => false,
        'running' => 0,
    ]);
});

it('renders the env roll-up table, one row per app', function (): void {
    $rows = [
        ['app' => 'shop', 'exists' => true, 'running' => 3, 'desired' => 3, 'pending' => 0, 'rolloutState' => 'COMPLETED', 'revision' => 'web:42', 'version' => '20260605-1'],
        ['app' => 'ghost', 'exists' => false, 'running' => 0, 'desired' => 0, 'pending' => 0, 'rolloutState' => null, 'revision' => null, 'version' => null],
    ];

    $rendered = implode("\n", envRollupProbe()->lines($rows));

    expect($rendered)
        ->toContain('shop')
        ->toContain('3/3')
        ->toContain('20260605-1')
        ->toContain('ghost')
        ->toContain('not deployed');
});
