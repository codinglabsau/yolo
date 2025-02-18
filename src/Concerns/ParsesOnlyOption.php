<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Enums\ServerGroup;

trait ParsesOnlyOption
{
    public function shouldRunOnGroup(ServerGroup $group, array $options): bool
    {
        return in_array($group, $this->parseOnlyOption($options['only'] ?? null));
    }

    public function parseOnlyOption(?string $only): array
    {
        if (! $only) {
            return [
                ServerGroup::WEB,
                ServerGroup::QUEUE,
                ServerGroup::SCHEDULER
            ];
        }

        $servers = [];

        $values = array_map('trim', explode(',', $only));

        foreach ($values as $server) {
            $servers[] = match ($server) {
                ServerGroup::WEB->value => ServerGroup::WEB,
                ServerGroup::QUEUE->value => ServerGroup::QUEUE,
                ServerGroup::SCHEDULER->value => ServerGroup::SCHEDULER,
            };
        }

        return $servers;
    }
}
