<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;

/**
 * The YOLO-provisioned services an app can opt into via the manifest's
 * `services` list. An entry is a bare capability name — the app declares WHAT
 * it consumes; all service shape (sizing, versions, hosts) is hardcoded or
 * belongs to the environment manifest (yolo-environment-{environment}.yml),
 * never the app manifest, so two apps can never declare competing
 * configuration for a shared service. A service need not have an env-manifest
 * half at all — mediaconvert (per-app IAM role + env injection, jobs on the
 * account default queue) and rekognition (a task-role grant on a pure
 * pay-per-call API) are app-side only.
 */
enum Service: string
{
    case IVS = 'ivs';
    case MEDIA_CONVERT = 'mediaconvert';
    case REKOGNITION = 'rekognition';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The IAM statements consuming this service adds to the app's ECS task
     * role policy — the app-side half of the service contract. Exhaustive by
     * design: adding a case without deciding its grants fails static analysis,
     * and an empty array is a valid decision (a service whose app side is env
     * injection only needs no runtime IAM).
     *
     * @return array<int, array<string, mixed>>
     */
    public function taskRoleStatements(): array
    {
        return match ($this) {
            // The app drives IVS itself at runtime — channels, stream keys
            // and streams are created on demand, so there are no stable
            // resource ARNs to scope to and the grant is service-wide. The
            // env-shared event-logging pipeline is the environment manifest's
            // concern, not this role's.
            self::IVS => [
                [
                    'Effect' => 'Allow',
                    'Resource' => '*',
                    'Action' => ['ivs:*'],
                ],
            ],
            // The app submits MediaConvert jobs at runtime. Job operations
            // carry no stable resource ARNs to scope to; the real boundary is
            // iam:PassRole — locked to this app's own MediaConvert role, and
            // only into the MediaConvert service itself.
            self::MEDIA_CONVERT => [
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
                    'Resource' => sprintf(
                        'arn:aws:iam::%s:role/%s',
                        Aws::accountId(),
                        Helpers::keyedResourceName(Iam::MEDIA_CONVERT_ROLE),
                    ),
                    'Action' => ['iam:PassRole'],
                    'Condition' => [
                        'StringEquals' => ['iam:PassedToService' => 'mediaconvert.amazonaws.com'],
                    ],
                ],
            ],
            // The detection APIs are resource-less — they operate on request
            // payloads or S3 objects read with the caller's own credentials,
            // so the grant is service-wide and S3 access rides the app's
            // bucket statements.
            self::REKOGNITION => [
                [
                    'Effect' => 'Allow',
                    'Resource' => '*',
                    'Action' => ['rekognition:*'],
                ],
            ],
        };
    }
}
