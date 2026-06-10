<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * The YOLO-provisioned services an app can opt into via the manifest's
 * `services` list. An entry is a bare capability name — the app declares WHAT
 * it consumes; all service shape (sizing, versions, hosts) belongs to the
 * environment manifest (yolo-{environment}.yml), never the app manifest, so
 * two apps can never declare competing configuration for a shared service.
 */
enum Service: string
{
    case IVS = 'ivs';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
