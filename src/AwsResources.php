<?php

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Concerns\UsesIam;

/**
 * Legacy state-lookup facade. The Resource pattern (src/Resources/*, backed by
 * the per-service src/Aws/* wrappers) has absorbed every other lookup; all that
 * remains are the v1-alpha EC2/MediaConvert IAM helpers still referenced by the
 * orphaned EnsureIamRolesExistStep. Both go away with that step's cleanup, after
 * which this facade can be deleted entirely.
 */
class AwsResources
{
    use UsesIam;
}
