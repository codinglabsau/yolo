<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;

class RetrieveEnvFileStep implements Step
{
    public function __invoke(array $options = []): void
    {
        $filename = sprintf(".env.%s", Helpers::environment());
        $path = array_key_exists('save-as', $options)
            ? $options['save-as']
            : Paths::base($filename);

        Aws::s3()->getObject([
            'Bucket' => Paths::s3ArtefactsBucket(),
            'Key' => $filename,
            'SaveAs' => $path,
        ]);
    }
}
