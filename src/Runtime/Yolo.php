<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * The runtime's front door — the root the {@see \Codinglabs\Yolo\Facades\Yolo}
 * facade resolves. Groups the package's in-app read surfaces so a consuming
 * app (and YOLO's own runtime code) reaches them as `Yolo::manifest()`
 * rather than knowing individual container bindings.
 */
class Yolo
{
    public function __construct(protected Manifest $manifest) {}

    public function manifest(): Manifest
    {
        return $this->manifest;
    }
}
