<?php

namespace Codinglabs\Yolo\Steps\Logging;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesIvsStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncIvsEventBridgeRuleStep implements ExecutesIvsStep
{
    public function __invoke(array $options): StepResult
    {
        $name = self::ruleName();

        try {
            AwsResources::eventBridgeRule($name);

            if (! Arr::get($options, 'dry-run')) {
                Aws::eventBridge()->putRule([
                    'Name' => $name,
                    'Description' => 'YOLO managed IVS state change events',
                    'EventPattern' => json_encode(self::eventPattern()),
                    'State' => 'ENABLED',
                    ...Aws::tags([
                        'Name' => $name,
                    ]),
                ]);

                return StepResult::SYNCED;
            }

            return StepResult::WOULD_SYNC;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::eventBridge()->putRule([
                    'Name' => $name,
                    'Description' => 'YOLO managed IVS state change events',
                    'EventPattern' => json_encode(self::eventPattern()),
                    'State' => 'ENABLED',
                    ...Aws::tags([
                        'Name' => $name,
                    ]),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }

    public static function ruleName(): string
    {
        return Helpers::keyedResourceName('ivs-state-change');
    }

    public static function eventPattern(): array
    {
        return [
            'source' => ['aws.ivs'],
        ];
    }
}
