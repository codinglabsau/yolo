<?php

use Codinglabs\Yolo\Commands\AuditCommand;
use Codinglabs\Yolo\Commands\AuditAppCommand;
use Codinglabs\Yolo\Commands\AuditEnvironmentCommand;

it('registers the audit verbs under scope-grouped names', function () {
    expect((new AuditCommand())->getName())->toBe('audit')
        ->and((new AuditEnvironmentCommand())->getName())->toBe('audit:environment')
        ->and((new AuditAppCommand())->getName())->toBe('audit:app');
});

it('drops the legacy --app option from the audit command', function () {
    $definition = (new AuditCommand())->getDefinition();

    expect($definition->hasOption('app'))->toBeFalse()
        ->and($definition->hasOption('drift'))->toBeTrue()
        ->and($definition->hasArgument('environment'))->toBeTrue();
});

it('takes app as a required positional argument on audit:app', function () {
    $definition = (new AuditAppCommand())->getDefinition();

    expect($definition->hasArgument('environment'))->toBeTrue()
        ->and($definition->hasArgument('app'))->toBeTrue()
        ->and($definition->getArgument('app')->isRequired())->toBeTrue()
        ->and($definition->hasOption('app'))->toBeFalse()
        ->and($definition->hasOption('drift'))->toBeTrue();
});

it('exposes --drift consistently across all three audit verbs', function () {
    expect((new AuditCommand())->getDefinition()->hasOption('drift'))->toBeTrue()
        ->and((new AuditEnvironmentCommand())->getDefinition()->hasOption('drift'))->toBeTrue()
        ->and((new AuditAppCommand())->getDefinition()->hasOption('drift'))->toBeTrue();
});
