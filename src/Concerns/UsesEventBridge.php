<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesEventBridge
{
    protected static array $eventBridgeRules = [];

    public static function eventBridgeRule(string $name): array
    {
        if (isset(static::$eventBridgeRules[$name])) {
            return static::$eventBridgeRules[$name];
        }

        $result = Aws::eventBridge()->describeRule([
            'Name' => $name,
        ]);

        if (empty($result['Name'])) {
            throw new ResourceDoesNotExistException("Could not find EventBridge rule with name $name");
        }

        static::$eventBridgeRules[$name] = $result->toArray();

        return static::$eventBridgeRules[$name];
    }
}
