<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Codinglabs\Yolo\ManifestReader;

/**
 * The runtime's front door — the root the {@see \Codinglabs\Yolo\Facades\Yolo}
 * facade resolves. Groups the package's in-app surfaces so a consuming app
 * (and YOLO's own runtime code) reaches them as `Yolo::manifest()` rather
 * than knowing individual container bindings. Accessors hand back the same
 * classes the CLI drives, so the two entry points share one logic surface.
 */
class Yolo
{
    public function __construct(protected ManifestReader $manifest) {}

    public function manifest(): ManifestReader
    {
        return $this->manifest;
    }
}
