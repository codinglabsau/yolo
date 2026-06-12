<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

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
}
