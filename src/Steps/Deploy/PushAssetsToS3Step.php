<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Aws\Command;
use Aws\S3\Transfer;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Illuminate\Filesystem\Filesystem;

class PushAssetsToS3Step implements Step
{
    public function __construct(
        protected string $environment,
        protected        $filesystem = new Filesystem()
    ) {}

    public function __invoke(): void
    {
        $appVersion = $this->filesystem->get(Paths::version());

        $manager = new Transfer(
            client: Aws::s3(),
            source: Paths::buildAssets(),
            dest: Paths::s3BuildAssets($appVersion),
            options: [
                'before' => function (Command $command) {
                    if (in_array($command->getName(), ['PutObject', 'CreateMultipartUpload'])) {
                        $command['ACL'] = 'public-read';
                    }
                },
            ]
        );

        $manager->transfer();
    }
}
