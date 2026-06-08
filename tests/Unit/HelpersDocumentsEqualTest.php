<?php

declare(strict_types=1);

use Codinglabs\Yolo\Helpers;

it('treats documents equal regardless of object key ordering', function (): void {
    $a = ['Version' => '2012-10-17', 'Statement' => [['Effect' => 'Allow', 'Action' => 's3:PutObject']]];
    $b = ['Statement' => [['Action' => 's3:PutObject', 'Effect' => 'Allow']], 'Version' => '2012-10-17'];

    expect(Helpers::documentsEqual($a, $b))->toBeTrue();
});

it('detects a genuine value difference', function (): void {
    $a = ['Effect' => 'Allow'];
    $b = ['Effect' => 'Deny'];

    expect(Helpers::documentsEqual($a, $b))->toBeFalse();
});

it('keeps list order significant', function (): void {
    expect(Helpers::documentsEqual(['Action' => ['a', 'b']], ['Action' => ['b', 'a']]))->toBeFalse();
});

it('treats a null (absent) document as unequal to a present one, and null equal to null', function (): void {
    expect(Helpers::documentsEqual(null, ['x' => 1]))->toBeFalse();
    expect(Helpers::documentsEqual(null, null))->toBeTrue();
});
