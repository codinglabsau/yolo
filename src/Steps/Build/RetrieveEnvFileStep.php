<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class RetrieveEnvFileStep implements Step
{
    public function __invoke(array $options = []): StepResult
    {
        $filename = sprintf('.env.%s', Helpers::environment());
        $path = array_key_exists('save-as', $options)
            ? $options['save-as']
            : Paths::base($filename);

        Aws::s3()->getObject([
            'Bucket' => Paths::s3ArtefactsBucket(),
            'Key' => $filename,
            'SaveAs' => $path,
        ]);

        return StepResult::SUCCESS;
    }
}
