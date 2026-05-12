<?php

namespace Codinglabs\Yolo\Steps\Recording;

use ZipArchive;
use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\Lambda\Exception\LambdaException;

use function Laravel\Prompts\note;

class SyncIvsRemuxLambdaStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsRecordingEnabled()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsRealtimeRemuxWebhookUrl()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsWebhookSecret()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsRealtimeMainBucket()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsRemuxFfmpegLayerArn()) {
            note('Warning: aws.ivs.recording.ffmpeg_layer_arn is not set — Lambda will be deployed without the FFmpeg layer and will fail at runtime.');
        }

        $functionName = self::functionName();

        try {
            Aws::lambda()->getFunction(['FunctionName' => $functionName]);

            if (! Arr::get($options, 'dry-run')) {
                Aws::lambda()->updateFunctionCode([
                    'FunctionName' => $functionName,
                    'ZipFile' => $this->buildZip(),
                ]);

                $this->waitForUpdate($functionName);

                Aws::lambda()->updateFunctionConfiguration([
                    'FunctionName' => $functionName,
                    'Runtime' => 'python3.12',
                    'Handler' => 'lambda_function.handler',
                    'Timeout' => 900,
                    'MemorySize' => 1024,
                    'EphemeralStorage' => ['Size' => 10240],
                    'Environment' => ['Variables' => $this->envVars()],
                    'Layers' => $this->layers(),
                ]);

                $this->syncEventBridgePermission($functionName);

                return StepResult::SYNCED;
            }

            return StepResult::WOULD_SYNC;
        } catch (LambdaException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }

        if (! Arr::get($options, 'dry-run')) {
            $role = AwsResources::lambdaIvsRemuxRole();

            Aws::lambda()->createFunction([
                'FunctionName' => $functionName,
                'Runtime' => 'python3.12',
                'Handler' => 'lambda_function.handler',
                'Role' => $role['Arn'],
                'Code' => ['ZipFile' => $this->buildZip()],
                'Timeout' => 900,
                'MemorySize' => 1024,
                'EphemeralStorage' => ['Size' => 10240],
                'Environment' => ['Variables' => $this->envVars()],
                'Layers' => $this->layers(),
                ...Aws::tags(['Name' => $functionName], associative: true),
            ]);

            $this->waitForActive($functionName);
            $this->syncEventBridgePermission($functionName);

            return StepResult::CREATED;
        }

        return StepResult::WOULD_CREATE;
    }

    private function envVars(): array
    {
        return [
            'MAIN_S3_BUCKET' => Manifest::ivsRealtimeMainBucket(),
            'WEBHOOK_URL' => Manifest::ivsRealtimeRemuxWebhookUrl(),
            'WEBHOOK_SECRET' => Manifest::ivsWebhookSecret(),
            'IVS_REGION' => Manifest::get('aws.region'),
        ];
    }

    private function layers(): array
    {
        $layerArn = Manifest::ivsRemuxFfmpegLayerArn();

        return $layerArn ? [$layerArn] : [];
    }

    private function buildZip(): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'yolo-lambda') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile(dirname(__DIR__, 3) . '/resources/lambda/ivs_realtime_remux.py', 'lambda_function.py');
        $zip->close();

        $content = file_get_contents($zipPath);
        unlink($zipPath);

        return $content;
    }

    private function syncEventBridgePermission(string $functionName): void
    {
        $region = Manifest::get('aws.region');
        $accountId = Aws::accountId();
        $ruleName = SyncIvsRealtimeRecordingEventBridgeRuleStep::ruleName();
        $ruleArn = "arn:aws:events:{$region}:{$accountId}:rule/{$ruleName}";

        try {
            Aws::lambda()->removePermission([
                'FunctionName' => $functionName,
                'StatementId' => 'AllowEventBridgeInvoke',
            ]);
        } catch (\Exception) {
            // Permission may not exist yet — that's fine
        }

        Aws::lambda()->addPermission([
            'FunctionName' => $functionName,
            'StatementId' => 'AllowEventBridgeInvoke',
            'Action' => 'lambda:InvokeFunction',
            'Principal' => 'events.amazonaws.com',
            'SourceArn' => $ruleArn,
        ]);
    }

    private function waitForActive(string $functionName): void
    {
        $attempts = 0;

        while ($attempts < 30) {
            $fn = Aws::lambda()->getFunctionConfiguration(['FunctionName' => $functionName]);

            if ($fn['State'] === 'Active') {
                return;
            }

            sleep(2);
            $attempts++;
        }
    }

    private function waitForUpdate(string $functionName): void
    {
        $attempts = 0;

        while ($attempts < 30) {
            $fn = Aws::lambda()->getFunctionConfiguration(['FunctionName' => $functionName]);

            if (($fn['LastUpdateStatus'] ?? 'Successful') === 'Successful') {
                return;
            }

            sleep(2);
            $attempts++;
        }
    }

    public static function functionName(): string
    {
        return Helpers::keyedResourceName('ivs-realtime-remux');
    }
}
