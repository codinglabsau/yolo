<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\EventBridge;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncEventBridgeMediaConvertRuleTargetStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('aws.mediaconvert')) {
            return StepResult::SKIPPED;
        }

        try {
            AwsResources::eventBridgeMediaConvertRuleTarget();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            $topic = AwsResources::mediaConvertTopic();
            $ruleName = Helpers::keyedResourceName(EventBridge::MEDIA_CONVERT_RULE);
            $ruleTargetId = Helpers::keyedResourceName(EventBridge::MEDIA_CONVERT_RULE_TARGET);

            if (! Arr::get($options, 'dry-run')) {
                Aws::eventBridge()->putTargets([
                    'Rule' => $ruleName,
                    'Targets' => [
                        [
                            'Arn' => $topic['TopicArn'],
                            'Id' => $ruleTargetId,
                        ],
                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
