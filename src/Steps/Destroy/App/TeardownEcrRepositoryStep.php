<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

/**
 * Tears down this app's ECR repository.
 */
class TeardownEcrRepositoryStep extends TeardownStep
{
    protected function resource(): EcrRepository
    {
        return new EcrRepository();
    }
}
