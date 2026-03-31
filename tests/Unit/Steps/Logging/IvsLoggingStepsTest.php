<?php

use Codinglabs\Yolo\Helpers;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Logging\SyncIvsEventBridgeRuleStep;
use Codinglabs\Yolo\Steps\Logging\SyncIvsEventBridgeTargetStep;
use Codinglabs\Yolo\Steps\Logging\SyncIvsCloudWatchLogGroupStep;

$tempDir = sys_get_temp_dir() . '/yolo-test-ivs';

beforeAll(function () use ($tempDir) {
    @mkdir($tempDir, 0755, true);

    if (! defined('BASE_PATH')) {
        define('BASE_PATH', $tempDir);
    }
});

beforeEach(function () use ($tempDir) {
    $this->tempDir = $tempDir;
    Helpers::app()->instance('environment', 'testing');
});

afterAll(function () use ($tempDir) {
    @unlink($tempDir . '/yolo.yml');
    @rmdir($tempDir);
});

function writeManifest(string $dir, array $config): void
{
    file_put_contents($dir . '/yolo.yml', Yaml::dump([
        'name' => 'test-app',
        'environments' => [
            'testing' => $config,
        ],
    ], 10, 2));
}

it('skips all steps when ivs logging is not configured', function () {
    writeManifest($this->tempDir, [
        'aws' => ['region' => 'us-west-2'],
    ]);

    expect((new SyncIvsCloudWatchLogGroupStep())([]))->toBe(StepResult::SKIPPED);
    expect((new SyncIvsEventBridgeRuleStep())([]))->toBe(StepResult::SKIPPED);
    expect((new SyncIvsEventBridgeTargetStep())([]))->toBe(StepResult::SKIPPED);
});

it('skips all steps when ivs logging is explicitly false', function () {
    writeManifest($this->tempDir, [
        'aws' => [
            'region' => 'us-west-2',
            'logging' => ['ivs' => false],
        ],
    ]);

    expect((new SyncIvsCloudWatchLogGroupStep())([]))->toBe(StepResult::SKIPPED);
    expect((new SyncIvsEventBridgeRuleStep())([]))->toBe(StepResult::SKIPPED);
    expect((new SyncIvsEventBridgeTargetStep())([]))->toBe(StepResult::SKIPPED);
});

it('generates correct resource names', function () {
    writeManifest($this->tempDir, [
        'aws' => ['region' => 'us-west-2'],
    ]);

    expect(SyncIvsCloudWatchLogGroupStep::logGroupName())
        ->toBe('/aws/ivs/yolo-testing-test-app');

    expect(SyncIvsEventBridgeRuleStep::ruleName())
        ->toBe('yolo-testing-test-app-ivs-state-change');

    expect(SyncIvsEventBridgeRuleStep::eventPattern())
        ->toBe(['source' => ['aws.ivs']]);
});
