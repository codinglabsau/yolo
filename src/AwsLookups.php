<?php

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Concerns\UsesS3;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Codinglabs\Yolo\Concerns\UsesEcr;
use Codinglabs\Yolo\Concerns\UsesEcs;
use Codinglabs\Yolo\Concerns\UsesIam;
use Codinglabs\Yolo\Concerns\UsesRds;
use Codinglabs\Yolo\Concerns\UsesSns;
use Codinglabs\Yolo\Concerns\UsesSqs;
use Codinglabs\Yolo\Concerns\UsesSsm;
use Codinglabs\Yolo\Concerns\UsesRoute53;
use Codinglabs\Yolo\Concerns\UsesCloudWatch;
use Codinglabs\Yolo\Concerns\UsesEventBridge;
use Codinglabs\Yolo\Concerns\UsesCloudWatchLogs;
use Codinglabs\Yolo\Concerns\UsesCertificateManager;
use Codinglabs\Yolo\Concerns\UsesElasticLoadBalancingV2;

class AwsLookups
{
    use UsesCertificateManager;
    use UsesCloudWatch;
    use UsesCloudWatchLogs;
    use UsesEc2;
    use UsesEcr;
    use UsesEcs;
    use UsesElasticLoadBalancingV2;
    use UsesEventBridge;
    use UsesIam;
    use UsesRds;
    use UsesRoute53;
    use UsesS3;
    use UsesSns;
    use UsesSqs;
    use UsesSsm;
}
