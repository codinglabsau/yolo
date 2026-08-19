<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Codinglabs\Yolo\ManifestReader;

/**
 * The root the {@see \Codinglabs\Yolo\Facades\Yolo} facade resolves —
 * accessors hand back the same classes the CLI drives.
 */
class Yolo
{
    public function __construct(protected ManifestReader $manifest) {}

    public function manifest(): ManifestReader
    {
        return $this->manifest;
    }
}
