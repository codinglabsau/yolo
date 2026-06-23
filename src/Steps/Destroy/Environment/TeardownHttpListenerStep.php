<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\ElbV2\HttpListener;

/**
 * Tears down the :80 listener (the apex/HTTP redirect entry point).
 */
class TeardownHttpListenerStep extends TeardownStep
{
    protected function resource(): HttpListener
    {
        return new HttpListener();
    }
}
