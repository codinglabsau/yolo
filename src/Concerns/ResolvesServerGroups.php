<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * Resolves the `--group` option (a comma-separated list of web/queue/scheduler)
 * against the app's actual service groups, so a deploy can target a subset of
 * services. No `--group` means every group the app runs; an unknown group, or one
 * the app doesn't run as its own service, is a hard error rather than a silent
 * no-op.
 */
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
