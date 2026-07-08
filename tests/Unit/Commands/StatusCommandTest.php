<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Commands\StatusCommand;
use Codinglabs\Yolo\Commands\StatusAppCommand;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Codinglabs\Yolo\Commands\StatusEnvironmentCommand;

// The status dashboard's display logic lives in pure static helpers on the
// RendersServiceStatus trait, reached here through StatusCommand. They take plain
// arrays (the shapes AWS returns) so they can be pinned without mocking AWS.

it('is the status dashboard, with --snapshot and --json one-shot escape hatches', function (): void {
    $command = new StatusCommand();
    $definition = $command->getDefinition();

    expect($command->getName())->toBe('status')
        ->and($definition->getArgument('environment')->isRequired())->toBeTrue()
        ->and($definition->hasOption('json'))->toBeTrue()
        ->and($definition->hasOption('snapshot'))->toBeTrue();
});

it('opens the dashboard only in an interactive, decorated terminal without --json/--snapshot', function (): void {
    expect(StatusCommand::shouldRenderDashboard(json: false, snapshot: false, interactive: true, decorated: true))->toBeTrue()
        ->and(StatusCommand::shouldRenderDashboard(json: true, snapshot: false, interactive: true, decorated: true))->toBeFalse()    // --json → snapshot
        ->and(StatusCommand::shouldRenderDashboard(json: false, snapshot: true, interactive: true, decorated: true))->toBeFalse()    // --snapshot → snapshot
        ->and(StatusCommand::shouldRenderDashboard(json: false, snapshot: false, interactive: false, decorated: true))->toBeFalse()  // piped stdin → snapshot
        ->and(StatusCommand::shouldRenderDashboard(json: false, snapshot: false, interactive: true, decorated: false))->toBeFalse(); // non-decorated → snapshot
});

it('clamps the progress bar between empty and full', function (): void {
    expect(StatusCommand::progressBar(0, 4, 8))->toBe(str_repeat('░', 8));
    expect(StatusCommand::progressBar(4, 4, 8))->toBe(str_repeat('█', 8));
    expect(StatusCommand::progressBar(2, 4, 8))->toBe(str_repeat('█', 4) . str_repeat('░', 4));
    // Never overflows when running exceeds desired (mid-rollout overlap).
    expect(StatusCommand::progressBar(6, 4, 8))->toBe(str_repeat('█', 8));
    // No desired count reads as complete, not divide-by-zero.
    expect(StatusCommand::progressBar(0, 0, 8))->toBe(str_repeat('█', 8));
});

it('parses the app version from a tagged image and skips digests / untagged refs', function (): void {
    expect(StatusCommand::versionFromImage('1234.dkr.ecr.ap-southeast-2.amazonaws.com/yolo-prod-app:20260605-1'))
        ->toBe('20260605-1');
    // A digest reference has no human version.
    expect(StatusCommand::versionFromImage('1234.dkr.ecr.ap-southeast-2.amazonaws.com/yolo-prod-app@sha256:abcdef'))
        ->toBeNull();
    // A registry host:port with no tag is not a version.
    expect(StatusCommand::versionFromImage('registry.example.com:5000/yolo-app'))->toBeNull();
    expect(StatusCommand::versionFromImage(''))->toBeNull();
});

it('reduces a task-definition ARN to group:revision', function (): void {
    expect(StatusCommand::revisionLabel('arn:aws:ecs:ap-southeast-2:1234:task-definition/yolo-prod-app-web:42'))
        ->toBe('web:42');
    expect(StatusCommand::revisionLabel('arn:aws:ecs:ap-southeast-2:1234:task-definition/yolo-prod-app-queue:7'))
        ->toBe('queue:7');
    expect(StatusCommand::revisionLabel(null))->toBeNull();
});

it('formats the task spec from CPU units / memory MiB', function (): void {
    expect(StatusCommand::formatSpec('512', '1024', 'FARGATE'))->toBe('0.5 vCPU · 1 GB · FARGATE');
    expect(StatusCommand::formatSpec('256', '512', 'SPOT'))->toBe('0.25 vCPU · 0.5 GB · SPOT');
    expect(StatusCommand::formatSpec('1024', '2048', 'FARGATE'))->toBe('1 vCPU · 2 GB · FARGATE');
    expect(StatusCommand::formatSpec(null, null, 'FARGATE'))->toBe('—');
});

it('colours the task count by convergence', function (): void {
    expect(StatusCommand::formatTasks(2, 2, 0))->toContain('2/2')->toContain('green');
    expect(StatusCommand::formatTasks(0, 1, 0))->toContain('0/1')->toContain('red');
    expect(StatusCommand::formatTasks(1, 2, 1))->toContain('1/2')->toContain('yellow');
    expect(StatusCommand::formatTasks(0, 0, 0))->toContain('0/0')->toContain('gray');
});

it('describes scaling bounds, policies, or a fixed/singleton service', function (): void {
    expect(StatusCommand::formatScaling(null, ServerGroup::SCHEDULER))->toBe('singleton');
    expect(StatusCommand::formatScaling(null, ServerGroup::WEB))->toBe('fixed');

    $scaling = [
        'min' => 1,
        'max' => 4,
        'policies' => [
            ['metric' => 'ECSServiceAverageCPUUtilization', 'target' => 65.0],
            ['metric' => 'concurrency', 'target' => 23.0],
            ['metric' => 'burst', 'target' => 0.0],
        ],
    ];

    expect(StatusCommand::formatScaling($scaling, ServerGroup::WEB))
        ->toBe('1–4 auto (cpu 65%, concurrency 23, burst)');

    $queue = ['min' => 0, 'max' => 10, 'policies' => [['metric' => 'backlog', 'target' => 0.0]]];

    expect(StatusCommand::formatScaling($queue, ServerGroup::QUEUE))->toBe('0–10 auto (backlog)');
});

it('ranks scaling policies into a stable display order', function (): void {
    $probe = new class()
    {
        use RendersServiceStatus;

        public function rank(string $metric): int
        {
            return self::policyRank($metric);
        }
    };

    // DescribeScalingPolicies has no ordering guarantee, so the overview sorts by
    // rank — cpu, concurrency, burst, backlog, then anything unrecognised.
    $shuffled = ['burst', 'custom', 'concurrency', 'backlog', 'ECSServiceAverageCPUUtilization'];

    usort($shuffled, fn (string $a, string $b): int => $probe->rank($a) <=> $probe->rank($b));

    expect($shuffled)->toBe(['ECSServiceAverageCPUUtilization', 'concurrency', 'burst', 'backlog', 'custom']);
});

it('reduces a live policy to its metric and target', function (): void {
    $probe = new class()
    {
        use RendersServiceStatus;

        /** @param array<string, mixed> $policy */
        public function view(array $policy): ?array
        {
            return self::policyView($policy);
        }
    };

    // The customized-metric concurrency policy carries no PredefinedMetricType, so
    // it's recognised by its policy name.
    expect($probe->view([
        'PolicyName' => 'yolo-testing-my-app-concurrency-scaling-policy',
        'TargetTrackingScalingPolicyConfiguration' => [
            'TargetValue' => 23.0,
            'CustomizedMetricSpecification' => ['Metrics' => []],
        ],
    ]))->toBe(['metric' => 'concurrency', 'target' => 23.0]);

    // A predefined policy reduces to its metric type.
    expect($probe->view([
        'PolicyName' => 'yolo-testing-my-app-cpu-scaling-policy',
        'TargetTrackingScalingPolicyConfiguration' => [
            'TargetValue' => 65.0,
            'PredefinedMetricSpecification' => ['PredefinedMetricType' => 'ECSServiceAverageCPUUtilization'],
        ],
    ]))->toBe(['metric' => 'ECSServiceAverageCPUUtilization', 'target' => 65.0]);

    // A step-scaling policy has no target-tracking config — named by which one it is:
    // the web burst policy, else the queue backlog/scale-to-zero bootstrap.
    expect($probe->view(['PolicyName' => 'yolo-prod-app-web-burst-policy', 'StepScalingPolicyConfiguration' => []]))
        ->toBe(['metric' => 'burst', 'target' => 0.0]);
    expect($probe->view(['PolicyName' => 'yolo-prod-app-queue-bootstrap-policy', 'StepScalingPolicyConfiguration' => []]))
        ->toBe(['metric' => 'backlog', 'target' => 0.0]);
});

it('formats live load against the CPU target, with web-only request/response', function (): void {
    $webLoad = ['cpu' => 43.2, 'memory' => 38.0, 'requests' => 410.0, 'response' => 0.126];

    expect(StatusCommand::formatLoad($webLoad, 65.0, ServerGroup::WEB))
        ->toBe('cpu 43.2%/65% · mem 38% · 410 rpm · 126 ms');

    // No CPU target → just the current reading; missing metric → em dash.
    $queueLoad = ['cpu' => null, 'memory' => 12.0, 'requests' => null, 'response' => null];

    expect(StatusCommand::formatLoad($queueLoad, null, ServerGroup::QUEUE))
        ->toBe('cpu — · mem 12%');
});

it('renders a single-row braille sparkline scaled to the series, and nothing for an empty one', function (): void {
    expect(StatusCommand::sparkline([]))->toBe('');

    // Two datapoints per braille cell → about half as many characters as points.
    expect(mb_strlen(StatusCommand::sparkline([0.0, 50.0, 100.0])))->toBe(2)
        ->and(mb_strlen(StatusCommand::sparkline([1.0, 2.0, 3.0, 4.0])))->toBe(2)
        ->and(StatusCommand::sparkline([0.0, 50.0, 100.0]))->toMatch('/[\x{2800}-\x{28ff}]/u');

    // A flat series draws a low baseline rather than blanking out.
    $flat = StatusCommand::sparkline([5.0, 5.0, 5.0]);
    expect(mb_strlen($flat))->toBe(2)
        ->and($flat)->not->toBe(str_repeat("\u{2800}", 2));   // not blank braille
});

it('trails a load reading with a gray sparkline when a series is present', function (): void {
    $load = [
        'cpu' => 43.2,
        'memory' => 38.0,
        'requests' => 410.0,
        'response' => 0.126,
        'series' => [
            'cpu' => [10.0, 20.0, 43.2],
            'memory' => [38.0, 38.0, 38.0],
            'requests' => [100.0, 300.0, 410.0],
            'response' => [0.1, 0.12, 0.126],
        ],
    ];

    expect(StatusCommand::formatLoad($load, 65.0, ServerGroup::WEB))
        ->toContain('cpu 43.2%/65%')
        ->toContain('mem 38%')
        ->toContain('410 rpm')
        ->toContain('<fg=gray>')               // sparklines render gray
        ->toMatch('/[\x{2800}-\x{28ff}]/u');   // a braille sparkline glyph is present
});

it('formats the queue backlog, gray "empty" when drained', function (): void {
    expect(StatusCommand::formatBacklog(0))->toContain('empty')->toContain('gray');
    expect(StatusCommand::formatBacklog(1))->toBe('1 pending');
    expect(StatusCommand::formatBacklog(12500))->toBe('12,500 pending');
});

it('lists the solo queue, or landlord + tenants when multi-tenant', function (): void {
    $probe = new class()
    {
        use RendersServiceStatus;

        /** @return array<string, string> */
        public function names(): array
        {
            return self::queueNames();
        }

        /**
         * @param  array<int, array{label: string, name: string, backlog: int}>  $queues
         * @return array<int, string>
         */
        public function lines(array $queues): array
        {
            return $this->queueLines($queues);
        }
    };

    writeManifest([]);
    expect($probe->names())->toBe(['queue' => 'yolo-testing-my-app']);

    writeManifest(['tenants' => ['acme' => [], 'globex' => []]]);
    expect($probe->names())->toBe([
        'landlord' => 'yolo-testing-my-app-landlord',
        'acme' => 'yolo-testing-my-app-acme',
        'globex' => 'yolo-testing-my-app-globex',
    ]);

    // No queues → no panel; a queue → a labelled backlog row.
    expect($probe->lines([]))->toBe([]);
    expect(implode("\n", $probe->lines([['label' => 'queue', 'name' => 'yolo-testing-my-app', 'backlog' => 7]])))
        ->toContain('Queue')
        ->toContain('queue')
        ->toContain('7 pending');
});

it('colours the rollout state', function (): void {
    expect(StatusCommand::formatRolloutState('IN_PROGRESS'))->toContain('IN PROGRESS')->toContain('blue');
    expect(StatusCommand::formatRolloutState('COMPLETED'))->toContain('COMPLETED')->toContain('green');
    expect(StatusCommand::formatRolloutState('FAILED'))->toContain('FAILED')->toContain('red');
    expect(StatusCommand::formatRolloutState(null))->toContain('—');
});

it('times an in-progress rollout from createdAt and a settled one across its span', function (): void {
    $now = 1_000;

    $inProgress = ['rolloutState' => 'IN_PROGRESS', 'createdAt' => new DateTimeImmutable('@940')];
    expect(StatusCommand::runningTime($inProgress, $now))->toBe(60);

    $completed = [
        'rolloutState' => 'COMPLETED',
        'createdAt' => new DateTimeImmutable('@800'),
        'updatedAt' => new DateTimeImmutable('@985'),
    ];
    expect(StatusCommand::runningTime($completed, $now))->toBe(185);
});

it('picks out in-progress and failed deployments', function (): void {
    $statuses = [
        ['group' => ServerGroup::WEB, 'rolloutState' => 'IN_PROGRESS'],
        ['group' => ServerGroup::QUEUE, 'rolloutState' => 'COMPLETED'],
        ['group' => ServerGroup::SCHEDULER, 'rolloutState' => 'FAILED'],
    ];

    expect(StatusCommand::inProgressDeployments($statuses))->toHaveCount(1);
    expect(StatusCommand::anyDeploymentFailed($statuses))->toBeTrue();

    $settled = [['group' => ServerGroup::WEB, 'rolloutState' => 'COMPLETED']];
    expect(StatusCommand::inProgressDeployments($settled))->toBe([]);
    expect(StatusCommand::anyDeploymentFailed($settled))->toBeFalse();
});

it('reads the launch type, defaulting to FARGATE and detecting Spot', function (): void {
    expect(StatusCommand::launchType(['launchType' => 'FARGATE']))->toBe('FARGATE');
    expect(StatusCommand::launchType([
        'capacityProviderStrategy' => [['capacityProvider' => 'FARGATE_SPOT', 'weight' => 1]],
    ]))->toBe('SPOT');
    expect(StatusCommand::launchType([]))->toBe('FARGATE');
});

it('serialises gathered status rows into a clean, encodable json shape', function (): void {
    $statuses = [
        [
            'group' => ServerGroup::WEB,
            'exists' => true,
            'running' => 2,
            'desired' => 2,
            'pending' => 0,
            'launch' => 'FARGATE',
            'cpu' => '512',
            'memory' => '1024',
            'revision' => 'web:42',
            'version' => '20260605-1',
            // The raw primary deployment blob carries DateTimeInterface timestamps;
            // it must be dropped from the machine contract.
            'primary' => ['createdAt' => new DateTimeImmutable('@1000')],
            'rolloutState' => 'COMPLETED',
            'rolloutReason' => null,
            'scaling' => ['min' => 1, 'max' => 4, 'policies' => [['metric' => 'ECSServiceAverageCPUUtilization', 'target' => 65.0]]],
            'cpuTarget' => 65.0,
            'load' => ['cpu' => 43.2, 'memory' => 38.0, 'requests' => 410.0, 'response' => 0.126],
        ],
    ];

    $json = StatusCommand::jsonStatuses($statuses);

    expect($json)->toBe([
        [
            'group' => 'web',
            'exists' => true,
            'tasks' => ['running' => 2, 'desired' => 2, 'pending' => 0],
            'spec' => ['cpu' => '512', 'memory' => '1024', 'launch' => 'FARGATE'],
            'revision' => 'web:42',
            'version' => '20260605-1',
            'rollout' => ['state' => 'COMPLETED', 'reason' => null],
            'scaling' => ['min' => 1, 'max' => 4, 'policies' => [['metric' => 'ECSServiceAverageCPUUtilization', 'target' => 65.0]]],
            'cpuTarget' => 65.0,
            'load' => ['cpu' => 43.2, 'memory' => 38.0, 'requests' => 410.0, 'response' => 0.126],
        ],
    ]);

    // No raw deployment blob, and the payload encodes to valid JSON cleanly.
    expect($json[0])->not->toHaveKey('primary');
    expect(json_encode($json))->toBeJson();
});

it('serialises a not-yet-deployed group without choking on null spec', function (): void {
    $statuses = [[
        'group' => ServerGroup::QUEUE,
        'exists' => false,
        'running' => 0,
        'desired' => 0,
        'pending' => 0,
        'launch' => 'FARGATE',
        'cpu' => null,
        'memory' => null,
        'revision' => null,
        'version' => null,
        'primary' => null,
        'rolloutState' => null,
        'rolloutReason' => null,
        'scaling' => null,
        'cpuTarget' => null,
        'load' => ['cpu' => null, 'memory' => null, 'requests' => null, 'response' => null],
    ]];

    expect(StatusCommand::jsonStatuses($statuses)[0])->toMatchArray([
        'group' => 'queue',
        'exists' => false,
        'spec' => ['cpu' => null, 'memory' => null, 'launch' => 'FARGATE'],
        'scaling' => null,
        'rollout' => ['state' => null, 'reason' => null],
    ]);
});

it('registers status:app and status:environment under the scope namespace', function (): void {
    $app = new StatusAppCommand();
    expect($app->getName())->toBe('status:app')
        ->and($app->getDefinition()->hasOption('json'))->toBeTrue();

    $env = new StatusEnvironmentCommand();
    expect($env->getName())->toBe('status:environment')
        ->and($env->getDefinition()->hasOption('json'))->toBeTrue()
        ->and($env->getDefinition()->hasArgument('environment'))->toBeTrue();
});

it('serialises the env roll-up into a clean json shape, dropping the deployment blob', function (): void {
    $rows = [[
        'app' => 'shop',
        'exists' => true,
        'running' => 3,
        'desired' => 3,
        'pending' => 0,
        'launch' => 'FARGATE',
        'primary' => ['createdAt' => new DateTimeImmutable('@1000')],
        'rolloutState' => 'COMPLETED',
        'rolloutReason' => null,
        'revision' => 'web:42',
        'cpu' => '512',
        'memory' => '1024',
        'version' => '20260605-1',
    ]];

    $json = StatusCommand::jsonEnvStatuses($rows);

    expect($json)->toBe([[
        'app' => 'shop',
        'exists' => true,
        'tasks' => ['running' => 3, 'desired' => 3, 'pending' => 0],
        'revision' => 'web:42',
        'version' => '20260605-1',
        'rollout' => ['state' => 'COMPLETED', 'reason' => null],
    ]]);

    expect($json[0])->not->toHaveKey('primary');
    expect(json_encode($json))->toBeJson();
});
