<?php

namespace Codinglabs\Yolo\Concerns;

use Closure;

trait HasAfterCallbacks
{
    protected array $after = [];

    public function after(Closure $callback): void
    {
        $this->after[] = $callback;
    }
}
