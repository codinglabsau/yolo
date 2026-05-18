<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\ServerGroup;

trait ParsesOnlyOption
{
    public function shouldRunOnGroup(ServerGroup $group, array $options): bool
    {
        if (! Manifest::hasServerGroup($group)) {
            return false;
        }

        return in_array($group, $this->parseOnlyOption($options['only'] ?? null));
    }

    public function parseOnlyOption(?string $only): array
    {
        if (! $only) {
            return ServerGroup::cases();
        }

        return array_map(
            fn (string $server) => ServerGroup::from(trim($server)),
            explode(',', $only),
        );
    }
}
