<?php

namespace Codinglabs\Yolo\Steps\Logging;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\EventBridge\Exception\EventBridgeException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncIvsRecordingEventBridgeTargetStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsEnabled()) {
            return StepResult::SKIPPED;
        }

        $webhookUrl = Manifest::get('aws.ivs.recording_webhook_url');

        if (! $webhookUrl) {
            return StepResult::SKIPPED;
        }

        $webhookSecret = Manifest::get('aws.ivs.recording_webhook_secret');

        if (! $webhookSecret) {
            return StepResult::SKIPPED;
        }

        $ruleName = SyncIvsRecordingEventBridgeRuleStep::ruleName();
        $connectionName = Helpers::keyedResourceName('ivs-recording-webhook-connection');
        $destinationName = Helpers::keyedResourceName('ivs-recording-webhook-destination');

        $connectionArn = $this->syncConnection($connectionName, $webhookSecret, $options);

        if (! $connectionArn) {
            return StepResult::WOULD_CREATE;
        }

        $destinationArn = $this->syncApiDestination($destinationName, $connectionArn, $webhookUrl, $options);

        if (! $destinationArn) {
            return StepResult::WOULD_CREATE;
        }

        $existingTarget = null;

        try {
            AwsResources::eventBridgeRule($ruleName);

            $existingTarget = collect(Aws::eventBridge()->listTargetsByRule([
                'Rule' => $ruleName,
            ])['Targets'])->first(
                fn ($target) => $target['Id'] === 'ivs-recording-webhook'
            );

            if ($existingTarget && $existingTarget['Arn'] === $destinationArn) {
                return StepResult::SYNCED;
            }
        } catch (ResourceDoesNotExistException) {
            // Rule doesn't exist yet — target needs to be created
        }

        if (! Arr::get($options, 'dry-run')) {
            Aws::eventBridge()->putTargets([
                'Rule' => $ruleName,
                'Targets' => [
                    [
                        'Id' => 'ivs-recording-webhook',
                        'Arn' => $destinationArn,
                        'HttpParameters' => [
                            'HeaderParameters' => [],
                            'QueryStringParameters' => [],
                        ],
                    ],
                ],
            ]);

            return $existingTarget
                ? StepResult::SYNCED
                : StepResult::CREATED;
        }

        return $existingTarget
            ? StepResult::WOULD_SYNC
            : StepResult::WOULD_CREATE;
    }

    private function syncConnection(string $name, string $secret, array $options): ?string
    {
        $authParameters = [
            'ApiKeyAuthParameters' => [
                'ApiKeyName' => 'X-Webhook-Secret',
                'ApiKeyValue' => $secret,
            ],
        ];

        try {
            $connection = Aws::eventBridge()->describeConnection(['Name' => $name]);

            if (! Arr::get($options, 'dry-run')) {
                Aws::eventBridge()->updateConnection([
                    'Name' => $name,
                    'AuthorizationType' => 'API_KEY',
                    'AuthParameters' => $authParameters,
                ]);
            }

            return $connection['ConnectionArn'];
        } catch (EventBridgeException) {
            // Does not exist — fall through to create
        }

        if (Arr::get($options, 'dry-run')) {
            return null;
        }

        $result = Aws::eventBridge()->createConnection([
            'Name' => $name,
            'Description' => 'YOLO managed connection for IVS recording webhook',
            'AuthorizationType' => 'API_KEY',
            'AuthParameters' => $authParameters,
        ]);

        return $result['ConnectionArn'];
    }

    private function syncApiDestination(string $name, string $connectionArn, string $webhookUrl, array $options): ?string
    {
        try {
            $destination = Aws::eventBridge()->describeApiDestination(['Name' => $name]);

            // Sync the URL in case it has changed
            if ($destination['InvocationEndpoint'] !== $webhookUrl && ! Arr::get($options, 'dry-run')) {
                Aws::eventBridge()->updateApiDestination([
                    'Name' => $name,
                    'ConnectionArn' => $connectionArn,
                    'InvocationEndpoint' => $webhookUrl,
                    'HttpMethod' => 'POST',
                ]);
            }

            return $destination['ApiDestinationArn'];
        } catch (EventBridgeException) {
            // Does not exist — fall through to create
        }

        if (Arr::get($options, 'dry-run')) {
            return null;
        }

        $result = Aws::eventBridge()->createApiDestination([
            'Name' => $name,
            'Description' => 'YOLO managed API destination for IVS recording webhook',
            'ConnectionArn' => $connectionArn,
            'InvocationEndpoint' => $webhookUrl,
            'HttpMethod' => 'POST',
        ]);

        return $result['ApiDestinationArn'];
    }
}
