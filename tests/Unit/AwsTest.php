<?php

use Codinglabs\Yolo\Aws;

describe('tags', function () {
    it('generates key-value tag format by default', function () {
        $result = Aws::tags(['Name' => 'my-resource']);

        expect($result)->toHaveKey('Tags');
        expect($result['Tags'])->toContain(
            ['Key' => 'yolo:environment', 'Value' => 'testing'],
            ['Key' => 'Name', 'Value' => 'my-resource'],
        );
    });

    it('generates associative tag format when flagged', function () {
        $result = Aws::tags(['Name' => 'my-resource'], wrap: 'tags', associative: true);

        expect($result)->toBe([
            'tags' => [
                'yolo:environment' => 'testing',
                'Name' => 'my-resource',
            ],
        ]);
    });

    it('supports custom wrap key', function () {
        $result = Aws::tags(['Name' => 'test'], wrap: 'TagSet');

        expect($result)->toHaveKey('TagSet');
        expect($result)->not->toHaveKey('Tags');
    });

    it('always includes environment tag', function () {
        $result = Aws::tags(associative: true);

        expect($result['Tags'])->toBe([
            'yolo:environment' => 'testing',
        ]);
    });
});
