<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;

/**
 * AWS Elemental MediaConvert (video transcoding). App-side only: a per-app
 * IAM role for MediaConvert to assume, jobs on the account default queue —
 * there is nothing to declare env-side.
 */
class MediaConvert extends ServiceDefinition
{
    public function service(): Service
    {
        return Service::MEDIA_CONVERT;
    }

    public function envBacked(): bool
    {
        return false;
    }

    /**
     * The app submits MediaConvert jobs at runtime. Job operations carry no
     * stable resource ARNs to scope to; the real boundary is iam:PassRole —
     * locked to this app's own MediaConvert role, and only into the
     * MediaConvert service itself.
     */
    public function taskRoleStatements(): array
    {
        return [
            [
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => [
                    'mediaconvert:CreateJob',
                    'mediaconvert:GetJob',
                    'mediaconvert:ListJobs',
                    'mediaconvert:DescribeEndpoints',
                ],
            ],
            [
                'Effect' => 'Allow',
                'Resource' => $this->roleArn(),
                'Action' => ['iam:PassRole'],
                'Condition' => [
                    'StringEquals' => ['iam:PassedToService' => 'mediaconvert.amazonaws.com'],
                ],
            ],
        ];
    }

    #[\Override]
    public function appSteps(): array
    {
        return [
            Steps\Sync\App\SyncMediaConvertRoleStep::class,
            Steps\Sync\App\AttachMediaConvertRolePoliciesStep::class,
        ];
    }

    /**
     * Consuming mediaconvert provisions a per-app role for MediaConvert to
     * assume; the app passes it on every CreateJob, so the computed ARN is
     * baked in at build.
     */
    #[\Override]
    public function buildValues(): array
    {
        return [
            'AWS_MEDIACONVERT_ROLE_ID' => $this->roleArn(),
        ];
    }

    #[\Override]
    public function dashboardContext(): array
    {
        // MediaConvert jobs run on the account default queue, so the panel is
        // account-level by nature — still worth charting on the consumer's
        // dashboard, since that's where someone debugging a stuck job looks.
        return [
            'mediaConvertQueueArn' => Manifest::usesService(Service::MEDIA_CONVERT)
                ? sprintf('arn:aws:mediaconvert:%s:%s:queues/Default', Manifest::get('region'), Aws::accountId())
                : null,
        ];
    }

    #[\Override]
    public function servicesWidgets(array $context): array
    {
        if ($context['mediaConvertQueueArn'] === null) {
            return [];
        }

        return [[
            'title' => 'MediaConvert jobs (account default queue)',
            'region' => $context['region'],
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 300,
            'stat' => 'Sum',
            'metrics' => [
                ['AWS/MediaConvert', 'JobsCompletedCount', 'Queue', $context['mediaConvertQueueArn'], ['label' => 'Completed', 'color' => Dashboard::GREEN]],
                ['AWS/MediaConvert', 'JobsErroredCount', 'Queue', $context['mediaConvertQueueArn'], ['label' => 'Errored', 'color' => Dashboard::RED]],
            ],
        ]];
    }

    protected function roleArn(): string
    {
        return sprintf(
            'arn:aws:iam::%s:role/%s',
            Aws::accountId(),
            Helpers::keyedResourceName(Iam::MEDIA_CONVERT_ROLE),
        );
    }
}
