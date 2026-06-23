<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\ElbV2\HttpsListener;

/**
 * Tears down the shared :443 listener. The services' search rule and the apps'
 * forward/redirect rules are gone by now (service teardown + destroy:app), so the
 * listener is unreferenced.
 */
class TeardownHttpsListenerStep extends TeardownStep
{
    protected function resource(): HttpsListener
    {
        return new HttpsListener();
    }
}
