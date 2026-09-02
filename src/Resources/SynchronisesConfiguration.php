<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources;

use Codinglabs\Yolo\Change;

/**
 * Opt-in for resources whose live config can drift after creation. Diff live
 * against desired and return the differing attributes; write only when $apply —
 * the plan pass passes false to report without writing.
 */
interface SynchronisesConfiguration
{
    /**
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array;
}
