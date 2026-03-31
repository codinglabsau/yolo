<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Aws\EventBridge\Exception\EventBridgeException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesEventBridge
{
    protected static array $eventBridgeRules = [];

    public static function eventBridgeRule(string $name): array
    {
        if (isset(static::$eventBridgeRules[$name])) {
            return static::$eventBridgeRules[$name];
        }

        try {
            $result = Aws::eventBridge()->describeRule([
                'Name' => $name,
            ]);
        } catch (EventBridgeException $e) {
            throw new ResourceDoesNotExistException("Could not find EventBridge rule with name $name");
        }

        static::$eventBridgeRules[$name] = $result->toArray();

        return static::$eventBridgeRules[$name];
    }

    public static function eventBridgeRuleTargets(string $ruleName): array
    {
        // Ensures the rule exists first, throws ResourceDoesNotExistException if not
        static::eventBridgeRule($ruleName);

        return Aws::eventBridge()->listTargetsByRule([
            'Rule' => $ruleName,
        ])['Targets'];
    }
}
