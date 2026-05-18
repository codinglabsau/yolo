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
            return [
                ServerGroup::WEB,
                ServerGroup::QUEUE,
                ServerGroup::SCHEDULER,
            ];
        }

        $servers = [];

        $values = array_map('trim', explode(',', $only));

        foreach ($values as $server) {
            $servers[] = ServerGroup::tryFrom($server)
                ?? throw new \InvalidArgumentException(sprintf(
                    'Unknown server group "%s". Valid values: %s.',
                    $server,
                    implode(', ', array_column(ServerGroup::cases(), 'value')),
                ));
        }

        return $servers;
    }
}
