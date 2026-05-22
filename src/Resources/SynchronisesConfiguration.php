<?php

namespace Codinglabs\Yolo\Resources;

/**
 * Optional Resource capability: reconcile live configuration (beyond tags) when
 * the resource already exists. The base Resource contract only guarantees tag
 * sync; resources whose config can drift after creation — currently just the
 * CloudFront distribution — implement this so `sync` pushes config changes onto
 * the existing resource instead of only fixing tags.
 */
interface SynchronisesConfiguration
{
    public function synchroniseConfiguration(): void;
}
