<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\EventBridge;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesEventBridge
{
    public static function eventBridgeMediaConvertRule(): array
    {
        $name = Helpers::keyedResourceName(EventBridge::MEDIA_CONVERT_RULE);

        $rules = Aws::eventBridge()->listRules();

        foreach ($rules['Rules'] as $rule) {
            if ($rule['Name'] === $name) {
                return $rule;
            }
        }

        throw new ResourceDoesNotExistException("Could not find EventBridge Rule with name $name");
    }

    public static function eventBridgeMediaConvertRuleTarget(): array
    {
        $ruleName = Helpers::keyedResourceName(EventBridge::MEDIA_CONVERT_RULE);
        $ruleTargetId = Helpers::keyedResourceName(EventBridge::MEDIA_CONVERT_RULE_TARGET);

        $targets = Aws::eventBridge()->listTargetsByRule([
            'Rule' => $ruleName,
        ]);

        foreach ($targets['Targets'] as $target) {
            if ($target['Id'] === $ruleTargetId) {
                return $target;
            }
        }

        throw new ResourceDoesNotExistException("Could not find EventBridge Target with id $ruleTargetId");
    }
}
