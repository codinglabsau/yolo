<?php

declare(strict_types=1);

use Codinglabs\Yolo\Commands\DestroyAppCommand;

it('names the protected app data bucket and the database in the confirmation', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'domain' => 'example.com',
        'bucket' => 'my-app-uploads',
        'tasks' => ['web' => true],
    ]);

    $resources = (new ReflectionMethod(DestroyAppCommand::class, 'protectedResources'))->invoke(new DestroyAppCommand());

    expect($resources)->toContain('App data bucket \'my-app-uploads\' — your data is safe')
        ->and(implode(' ', $resources))->toContain('Any RDS database — YOLO never deletes a database');
});

it('omits the app data bucket line when no data bucket is configured', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'domain' => 'example.com',
        'tasks' => ['web' => true],
    ]);

    $resources = (new ReflectionMethod(DestroyAppCommand::class, 'protectedResources'))->invoke(new DestroyAppCommand());

    expect(implode(' ', $resources))->not->toContain('App data bucket')
        ->and(implode(' ', $resources))->toContain('Any RDS database');
});
