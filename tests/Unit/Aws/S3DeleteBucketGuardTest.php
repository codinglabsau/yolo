<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'bucket' => 'my-app-uploads',
    ]);
});

it('refuses to delete the application data bucket', function (): void {
    $captured = [];
    bindRoutedS3Client(['DeleteBucket' => new Result([])], $captured);

    expect(fn (): mixed => S3::deleteBucket('my-app-uploads'))
        ->toThrow(IntegrityCheckException::class)
        ->and(array_column($captured, 'name'))->not->toContain('DeleteBucket');
});

it('deletes a bucket that is not the application data bucket', function (): void {
    $captured = [];
    bindRoutedS3Client(['DeleteBucket' => new Result([])], $captured);

    S3::deleteBucket('yolo-testing-config');

    expect(array_column($captured, 'name'))->toContain('DeleteBucket');
});
