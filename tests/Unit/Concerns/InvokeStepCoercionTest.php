<?php

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Regression guard for the #33 stepped-command refactor: build/deploy steps
 * return void (and ExecuteBuildStepsStep returns its sub-step array), so the
 * runner must coerce non-StepResult returns rather than hand them to the
 * StepResult|string-typed renderStatus(). Drives invokeStep directly.
 */
function statusFromInvoke(Step $step): StepResult|string
{
    $command = new SyncCommand();
    $command->input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());

    return (new ReflectionMethod($command, 'invokeStep'))
        ->invoke($command, $step, null, 'label', time(), [])['status'];
}

it('coerces a void step return to SUCCESS', function () {
    $step = new class() implements Step
    {
        public function __invoke(): void {}
    };

    expect(statusFromInvoke($step))->toBe(StepResult::SUCCESS);
});

it('coerces a HasSubSteps array return to SUCCESS', function () {
    $step = new class() implements Step
    {
        public function __invoke(): array
        {
            return ['some-build-command'];
        }
    };

    expect(statusFromInvoke($step))->toBe(StepResult::SUCCESS);
});

it('passes a StepResult return through unchanged', function () {
    $step = new class() implements Step
    {
        public function __invoke(): StepResult
        {
            return StepResult::CREATED;
        }
    };

    expect(statusFromInvoke($step))->toBe(StepResult::CREATED);
});

it('passes a custom string status through unchanged', function () {
    $step = new class() implements Step
    {
        public function __invoke(): string
        {
            return 'CUSTOM';
        }
    };

    expect(statusFromInvoke($step))->toBe('CUSTOM');
});
