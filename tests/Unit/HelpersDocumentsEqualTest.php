<?php

use Codinglabs\Yolo\Helpers;

it('treats documents equal regardless of object key ordering', function () {
    $a = ['Version' => '2012-10-17', 'Statement' => [['Effect' => 'Allow', 'Action' => 's3:PutObject']]];
    $b = ['Statement' => [['Action' => 's3:PutObject', 'Effect' => 'Allow']], 'Version' => '2012-10-17'];

    expect(Helpers::documentsEqual($a, $b))->toBeTrue();
});

it('detects a genuine value difference', function () {
    $a = ['Effect' => 'Allow'];
    $b = ['Effect' => 'Deny'];

    expect(Helpers::documentsEqual($a, $b))->toBeFalse();
});

it('keeps list order significant', function () {
    expect(Helpers::documentsEqual(['Action' => ['a', 'b']], ['Action' => ['b', 'a']]))->toBeFalse();
});

it('treats a null (absent) document as unequal to a present one, and null equal to null', function () {
    expect(Helpers::documentsEqual(null, ['x' => 1]))->toBeFalse();
    expect(Helpers::documentsEqual(null, null))->toBeTrue();
});
