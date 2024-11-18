<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;

trait UsesSsm
{
    public static function ubuntuAmiId(): string
    {
        // Ubuntu 22.04 LTS
        return Aws::ssm()->getParameter([
            'Name' => '/aws/service/canonical/ubuntu/server/22.04/stable/current/amd64/hvm/ebs-gp2/ami-id',
            'WithDecryption' => false,
        ])['Parameter']['Value'];
    }
}
