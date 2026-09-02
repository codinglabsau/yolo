<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

use Codinglabs\Yolo\Services\Ivs;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Services\Rekognition;
use Codinglabs\Yolo\Services\MediaConvert;
use Codinglabs\Yolo\Services\ServiceDefinition;

/**
 * An app declares WHAT it consumes; all service shape belongs to the environment
 * manifest, never the app manifest, so two apps can never declare competing
 * configuration for a shared service. The definition() match is exhaustive by
 * design: adding a case fails static analysis until the definition exists.
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
     * @return array<int, ServiceDefinition>
     */
    public static function definitions(): array
    {
        return array_map(fn (Service $service): ServiceDefinition => $service->definition(), self::cases());
    }

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
