<?php

declare(strict_types=1);

namespace Tests\Fixtures\Search;

use Codinglabs\Yolo\YoloServiceProvider;

/**
 * The provider as it boots in a Typesense app: manifest path pinned to a
 * fixture yolo.yml claiming the typesense service, so the search commands
 * register. (Testbench's skeleton base path has no manifest, which is the
 * not-a-Typesense-app case CommandRegistrationTest covers with the real
 * provider.)
 */
class ManifestedYoloServiceProvider extends YoloServiceProvider
{
    #[\Override]
    protected function manifestPath(): string
    {
        return __DIR__ . '/yolo.yml';
    }
}
