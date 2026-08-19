<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Codinglabs\Yolo\ManifestReader;

/**
 * The runtime's front door — the root the {@see \Codinglabs\Yolo\Facades\Yolo}
 * facade resolves. Accessors hand back the same classes the CLI drives, so
 * the two entry points share one logic surface.
 */
class Yolo
{
    public function __construct(protected ManifestReader $manifest) {}

    public function manifest(): ManifestReader
    {
        return $this->manifest;
    }
}
