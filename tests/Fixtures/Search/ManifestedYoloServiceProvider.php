<?php

declare(strict_types=1);

namespace Tests\Fixtures\Search;

use Codinglabs\Yolo\YoloServiceProvider;

/**
 * The provider as it boots in a Typesense app — manifest path pinned to a
 * fixture yolo.yml claiming the service.
 */
class ManifestedYoloServiceProvider extends YoloServiceProvider
{
    #[\Override]
    protected function manifestPath(): string
    {
        return __DIR__ . '/yolo.yml';
    }
}
