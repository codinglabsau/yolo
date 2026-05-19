<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

class EnsureResourceNameLengthsStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(): StepResult
    {
        $name = Helpers::keyedResourceName(exclusive: true);

        if (strlen($name) > 32) {
            $prefix = sprintf('yolo-%s-', $this->environment);
            $maxAppNameLength = 32 - strlen($prefix);

            throw new IntegrityCheckException(sprintf(
                "Target group / service name '%s' exceeds AWS's 32-char limit. Shorten `name` in yolo.yml to %d chars or less (current: '%s' — %d chars).",
                $name,
                $maxAppNameLength,
                Manifest::name(),
                strlen(Manifest::name()),
            ));
        }

        return StepResult::SYNCED;
    }
}
