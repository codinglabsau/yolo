<?php

declare(strict_types=1);

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\RestoreTemporaryEnvStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    if (! is_dir(Paths::build())) {
        mkdir(Paths::build(), 0755, true);
    }

    foreach (['.env.testing.tmp', '.env'] as $file) {
        if (file_exists(Paths::build($file))) {
            unlink(Paths::build($file));
        }
    }
});

it('restores the .tmp env file to .env and returns SUCCESS', function (): void {
    file_put_contents(Paths::build('.env.testing.tmp'), "APP_ENV=testing\n");

    $result = (new RestoreTemporaryEnvStep('testing'))();

    expect($result)->toBe(StepResult::SUCCESS)
        ->and(file_exists(Paths::build('.env.testing.tmp')))->toBeFalse()
        ->and(file_get_contents(Paths::build('.env')))->toBe("APP_ENV=testing\n");
});
