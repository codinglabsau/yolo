<?php

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Concerns\UsesS3;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Codinglabs\Yolo\Concerns\UsesRds;
use Codinglabs\Yolo\Concerns\UsesSns;
use Codinglabs\Yolo\Concerns\UsesSqs;
use Codinglabs\Yolo\Concerns\UsesSsm;
use Codinglabs\Yolo\Concerns\UsesRoute53;
use Codinglabs\Yolo\Concerns\UsesCloudWatch;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;
use Codinglabs\Yolo\Concerns\UsesAutoscaling;
use Codinglabs\Yolo\Concerns\UsesElasticTranscoder;
use Codinglabs\Yolo\Concerns\UsesCertificateManager;
use Codinglabs\Yolo\Concerns\UsesElasticLoadBalancingV2;

class AwsResources
{
    use UsesAutoscaling;
    use UsesCertificateManager;
    use UsesCloudWatch;
    use UsesCodeDeploy;
    use UsesEc2;
    use UsesElasticLoadBalancingV2;
    use UsesElasticTranscoder;
    use UsesRds;
    use UsesRoute53;
    use UsesS3;
    use UsesSns;
    use UsesSqs;
    use UsesSsm;
}
