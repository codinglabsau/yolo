<?php

declare(strict_types=1);

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\CreateTemporaryEnvStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    if (! is_dir(Paths::build())) {
        mkdir(Paths::build(), 0755, true);
    }

    foreach (['.env.testing', '.env.testing.tmp'] as $file) {
        if (file_exists(Paths::build($file))) {
            unlink(Paths::build($file));
        }
    }
});

it('moves the env file aside to a .tmp and returns SUCCESS', function (): void {
    file_put_contents(Paths::build('.env.testing'), "APP_ENV=testing\n");

    $result = (new CreateTemporaryEnvStep('testing'))();

    expect($result)->toBe(StepResult::SUCCESS)
        ->and(file_exists(Paths::build('.env.testing')))->toBeFalse()
        ->and(file_get_contents(Paths::build('.env.testing.tmp')))->toBe("APP_ENV=testing\n");
});
