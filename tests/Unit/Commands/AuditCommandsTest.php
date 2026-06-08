<?php

declare(strict_types=1);

use Codinglabs\Yolo\Commands\AuditCommand;
use Codinglabs\Yolo\Commands\AuditAppCommand;
use Codinglabs\Yolo\Commands\AuditEnvironmentCommand;

it('registers the audit verbs under scope-grouped names', function (): void {
    expect((new AuditCommand())->getName())->toBe('audit')
        ->and((new AuditEnvironmentCommand())->getName())->toBe('audit:environment')
        ->and((new AuditAppCommand())->getName())->toBe('audit:app');
});

it('drops the legacy --app option from the audit command', function (): void {
    $definition = (new AuditCommand())->getDefinition();

    expect($definition->hasOption('app'))->toBeFalse()
        ->and($definition->hasOption('unexpected'))->toBeTrue()
        ->and($definition->hasArgument('environment'))->toBeTrue();
});

it('takes app as a required positional argument on audit:app', function (): void {
    $definition = (new AuditAppCommand())->getDefinition();

    expect($definition->hasArgument('environment'))->toBeTrue()
        ->and($definition->hasArgument('app'))->toBeTrue()
        ->and($definition->getArgument('app')->isRequired())->toBeTrue()
        ->and($definition->hasOption('app'))->toBeFalse()
        ->and($definition->hasOption('unexpected'))->toBeTrue();
});

it('exposes --unexpected consistently across all three audit verbs', function (): void {
    expect((new AuditCommand())->getDefinition()->hasOption('unexpected'))->toBeTrue()
        ->and((new AuditEnvironmentCommand())->getDefinition()->hasOption('unexpected'))->toBeTrue()
        ->and((new AuditAppCommand())->getDefinition()->hasOption('unexpected'))->toBeTrue();
});
