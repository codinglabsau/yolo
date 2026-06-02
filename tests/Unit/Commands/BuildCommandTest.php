<?php

use Codinglabs\Yolo\Commands\BuildCommand;

function invokeAppHasAwsSdk(): bool
{
    return (new ReflectionMethod(new BuildCommand(), 'appHasAwsSdk'))->invoke(new BuildCommand());
}

afterEach(fn () => is_file(BASE_PATH . '/composer.lock') && unlink(BASE_PATH . '/composer.lock'));

it('sees aws/aws-sdk-php when it is a production dependency', function () {
    file_put_contents(BASE_PATH . '/composer.lock', json_encode([
        'packages' => [['name' => 'laravel/framework'], ['name' => 'aws/aws-sdk-php']],
    ]));

    expect(invokeAppHasAwsSdk())->toBeTrue();
});

it('does not count aws/aws-sdk-php when it is only a dev dependency', function () {
    file_put_contents(BASE_PATH . '/composer.lock', json_encode([
        'packages' => [['name' => 'laravel/framework']],
        'packages-dev' => [['name' => 'aws/aws-sdk-php']],
    ]));

    expect(invokeAppHasAwsSdk())->toBeFalse();
});

it('does not block when composer.lock is absent (cannot determine)', function () {
    expect(invokeAppHasAwsSdk())->toBeTrue();
});
