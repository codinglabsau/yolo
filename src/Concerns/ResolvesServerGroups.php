<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

trait ResolvesServerGroups
{
    /**
     * @return array<int, ServerGroup>
     */
    protected function resolveServerGroups(?string $only): array
    {
        $available = Manifest::serverGroups();

        if (! $only) {
            return $available;
        }

        return array_map(function (string $value) use ($available): ServerGroup {
            $group = ServerGroup::tryFrom(trim($value));

            if ($group === null || ! in_array($group, $available, true)) {
                throw new IntegrityCheckException(sprintf(
                    'Unknown --group "%s". This app runs: %s.',
                    trim($value),
                    implode(', ', array_map(fn (ServerGroup $group) => $group->value, $available)),
                ));
            }

            return $group;
        }, explode(',', $only));
    }
}
