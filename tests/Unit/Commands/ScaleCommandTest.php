<?php

use Codinglabs\Yolo\Yolo;
use Codinglabs\Yolo\Commands\ScaleCommand;

it('is named scale', function () {
    expect((new ScaleCommand())->getName())->toBe('scale');
});

it('is registered in the application', function () {
    $commands = (new ReflectionClass(Yolo::class))->getDefaultProperties()['commands'];

    expect($commands)->toContain(ScaleCommand::class);
});

it('compares desired count when the service is not autoscaling-managed', function () {
    expect(ScaleCommand::rows(managed: false, currentDesired: 1, running: 1, live: null, new: 3))->toBe([
        ['Desired count', '1', '3'],
        ['Running', '1', '—'],
    ]);
});

it('compares minimum capacity and marks desired count autoscaling-managed when managed', function () {
    expect(ScaleCommand::rows(managed: true, currentDesired: 2, running: 2, live: ['min' => 1, 'max' => 6], new: 3))->toBe([
        ['Min capacity', '1', '3'],
        ['Desired count', '— (autoscaling-managed)', '—'],
        ['Running', '2', '—'],
    ]);
});
