<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\EventBridge\Exception\EventBridgeException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EventBridge
{
    public static function rule(string $name): array
    {
        try {
            return Aws::eventBridge()->describeRule(['Name' => $name])->toArray();
        } catch (EventBridgeException) {
            throw new ResourceDoesNotExistException("Could not find EventBridge rule $name");
        }
    }
}
