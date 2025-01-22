<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Illuminate\Filesystem\Filesystem;

class PushArtefactToS3Step implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(): void
    {
        $appVersion = $this->filesystem->get(Paths::version());

        Aws::s3()->putObject([
            'Body' => file_get_contents(Paths::artefact()),
            'Bucket' => Paths::s3ArtefactsBucket(),
            'Key' => Paths::s3Artefacts($appVersion, Helpers::artefactName()),
        ]);
    }
}
