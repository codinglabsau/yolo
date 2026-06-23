<?php

declare(strict_types=1);

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Helpers;
use Symfony\Component\Console\Command\Command;
use Codinglabs\Yolo\Commands\DestroyAppCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Helpers::app()->instance('runningInAws', false);
});

function destroyReason(array $config): ?string
{
    writeManifest($config);

    $command = new DestroyAppCommand();

    return (new ReflectionMethod($command, 'unsupportedReason'))->invoke($command);
}

/**
 * @return array<int, class-string>
 */
function destroyPlanClasses(array $config): array
{
    writeManifest($config);

    $command = new DestroyAppCommand();
    $input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    [$plan] = (new ReflectionMethod($command, 'collateSteps'))->invoke($command, $command->scopes(), 'testing');

    return $plan->pluck('step')->map(fn (object $step): string => $step::class)->values()->all();
}

$soloWeb = ['domain' => 'example.com', 'tasks' => ['web' => ['port' => 8000]]];

it('refuses a partial teardown rather than orphaning resources', function (array $config, string $needle): void {
    expect(destroyReason($config))->toContain($needle);
})->with([
    'multi-tenant' => [['domain' => 'example.com', 'tasks' => ['web' => true], 'tenants' => ['t1' => ['domain' => 't1.example.com']]], 'multi-tenant'],
    'headless' => [['tasks' => ['web' => true]], 'headless'],
    'no web task' => [['domain' => 'example.com'], 'web task'],
]);

it('allows a standard solo web app', function () use ($soloWeb): void {
    expect(destroyReason($soloWeb))->toBeNull();
});

it('allows a solo web app that consumes services — their per-app teardown is modelled', function (): void {
    expect(destroyReason(['domain' => 'example.com', 'tasks' => ['web' => true], 'services' => ['typesense', 'rekognition']]))->toBeNull();
});

it('composes a used service\'s per-app teardown, ahead of the task SG it references', function (): void {
    $classes = destroyPlanClasses(['domain' => 'example.com', 'tasks' => ['web' => true], 'services' => ['typesense']]);
    $at = fn (string $class): int|false => array_search($class, $classes, true);

    expect($classes)->toContain(Steps\Destroy\App\RevokeTypesenseIngressStep::class);

    // The Typesense ingress is revoked before the task SG its rule references is deleted.
    expect($at(Steps\Destroy\App\RevokeTypesenseIngressStep::class))
        ->toBeLessThan($at(Steps\Destroy\App\TeardownTaskSecurityGroupStep::class));
});

it('composes the MediaConvert role teardown when the app uses mediaconvert', function (): void {
    expect(destroyPlanClasses(['domain' => 'example.com', 'tasks' => ['web' => true], 'services' => ['mediaconvert']]))
        ->toContain(Steps\Destroy\App\TeardownMediaConvertRoleStep::class);
});

it('tears resources down in reverse dependency order', function () use ($soloWeb): void {
    $classes = destroyPlanClasses($soloWeb);
    $at = fn (string $class): int|false => array_search($class, $classes, true);

    // A listener rule's action references the target group, so the rule goes first.
    expect($at(Steps\Destroy\App\TeardownForwardRuleStep::class))
        ->toBeLessThan($at(Steps\Destroy\App\TeardownTargetGroupStep::class));

    // The RDS-SG ingress rule references the task SG, so it's revoked before the SG is deleted.
    expect($at(Steps\Destroy\App\RevokeRdsIngressStep::class))
        ->toBeLessThan($at(Steps\Destroy\App\TeardownTaskSecurityGroupStep::class));

    // Drain the service before deleting its cluster; deregister autoscaling before the service.
    expect($at(Steps\Destroy\App\TeardownWebServiceStep::class))
        ->toBeLessThan($at(Steps\Destroy\App\TeardownEcsClusterStep::class));
    expect($at(Steps\Destroy\App\DeregisterWebAutoscalingStep::class))
        ->toBeLessThan($at(Steps\Destroy\App\TeardownWebServiceStep::class));

    // Stripping the environment from yolo.yml is the very last act; ECR (holding
    // the images) is the last AWS resource to go, just before it.
    expect($at(Steps\Destroy\App\RemoveEnvironmentFromManifestStep::class))->toBe(count($classes) - 1);
    expect($at(Steps\Destroy\App\TeardownEcrRepositoryStep::class))
        ->toBeLessThan($at(Steps\Destroy\App\RemoveEnvironmentFromManifestStep::class));
});

it('never includes a teardown for the BYO app data bucket', function () use ($soloWeb): void {
    // There is deliberately no S3Bucket teardown step — the data bucket must survive.
    expect(class_exists('Codinglabs\\Yolo\\Steps\\Destroy\\App\\TeardownS3BucketStep'))->toBeFalse();

    expect(destroyPlanClasses($soloWeb))->toContain(Steps\Destroy\App\TeardownS3AssetBucketStep::class)
        ->toContain(Steps\Destroy\App\TeardownS3ConfigBucketStep::class);
});

it('omits queue, scheduler and cache teardown for a plain solo web app', function () use ($soloWeb): void {
    expect(destroyPlanClasses($soloWeb))
        ->not->toContain(Steps\Destroy\App\TeardownQueueServiceStep::class)
        ->not->toContain(Steps\Destroy\App\TeardownSchedulerServiceStep::class)
        ->not->toContain(Steps\Destroy\App\DeregisterQueueAutoscalingStep::class);
});

it('reframes the runner wording as an irreversible destroy', function (): void {
    $command = new DestroyAppCommand();

    expect((new ReflectionMethod($command, 'planHeading'))->invoke($command))->toBe('Will destroy')
        ->and((new ReflectionMethod($command, 'confirmQuestion'))->invoke($command, 'production'))->toContain('Permanently delete')
        ->and((new ReflectionMethod($command, 'completionVerb'))->invoke($command))->toBe('Destroyed');
});

it('handle() refuses an unsupported app with FAILURE before any teardown runs', function (): void {
    // Multi-tenant is refused. No AWS client is bound, so if the guard failed to
    // short-circuit, reaching runScopes would error — a clean FAILURE proves the
    // reason -> FAILURE -> no-apply wiring, not just the reason string.
    writeManifest(['domain' => 'example.com', 'tasks' => ['web' => true], 'tenants' => ['t1' => ['domain' => 't1.example.com']]]);

    $command = new DestroyAppCommand();
    $input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    expect($command->handle())->toBe(Command::FAILURE);
});
