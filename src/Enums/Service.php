<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

use Codinglabs\Yolo\Services\Ivs;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Services\Rekognition;
use Codinglabs\Yolo\Services\MediaConvert;
use Codinglabs\Yolo\Services\ServiceDefinition;

/**
 * The name registry of YOLO-provisioned services an app can opt into via the
 * manifest's `services` list. An entry is a bare capability name — the app
 * declares WHAT it consumes; all service shape (sizing, versions, hosts) is
 * hardcoded or belongs to the environment manifest
 * (yolo-environment-{environment}.yml), never the app manifest, so two apps
 * can never declare competing configuration for a shared service.
 *
 * Everything else about a service lives on its definition (src/Services/) —
 * the composition root the enum resolves via definition(). The match is
 * exhaustive by design: adding a case fails static analysis until the
 * service's definition (and therefore all its decisions) exists.
 */
enum Service: string
{
    case IVS = 'ivs';
    case MEDIA_CONVERT = 'mediaconvert';
    case REKOGNITION = 'rekognition';
    case TYPESENSE = 'typesense';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Every service's definition, in case order — for the surfaces that
     * compose across all services (step lists, dashboard context/widgets).
     *
     * @return array<int, ServiceDefinition>
     */
    public static function definitions(): array
    {
        return array_map(fn (Service $service): ServiceDefinition => $service->definition(), self::cases());
    }

    /**
     * This service's key in the environment manifest — where the environment
     * declares it runs the service (`services.{name}`).
     */
    public function envManifestKey(): string
    {
        return 'services.' . $this->value;
    }

    public function definition(): ServiceDefinition
    {
        return match ($this) {
            self::IVS => new Ivs(),
            self::MEDIA_CONVERT => new MediaConvert(),
            self::REKOGNITION => new Rekognition(),
            self::TYPESENSE => new Typesense(),
        };
    }
}
