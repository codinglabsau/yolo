<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources;

/**
 * delete() removes the live resource and everything only it owns; it is only
 * ever called on an existing resource, behind the plan's confirm gate.
 */
interface Deletable
{
    public function delete(): void;
}
