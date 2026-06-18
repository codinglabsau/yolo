<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;

/**
 * Tears down the app's CloudFront asset distribution. LongRunning — the delete
 * disables the distribution and blocks on the DistributionDeployed waiter
 * (~5–15 min) before removing it.
 */
class TeardownAssetDistributionStep extends TeardownStep implements LongRunning
{
    protected function resource(): AssetDistribution
    {
        return new AssetDistribution();
    }

    public function patienceMessage(): string
    {
        return 'Disabling and deleting the CloudFront asset distribution — usually 5–15 minutes.';
    }
}
