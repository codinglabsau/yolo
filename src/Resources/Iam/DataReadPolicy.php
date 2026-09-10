<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Read access to the *data* the environment's apps hold — every YOLO-named app
 * data bucket — as its own document rather than a statement on {@see ObserverPolicy}.
 * Infra reads and data reads are separate axes: the per-app observer document is
 * also the CI deployer's fence, and a deploy grant must never be able to read user
 * uploads. Only the human tier roles carry this.
 *
 * Bring-your-own buckets are deliberately NOT here. Their names are only known
 * from the apps' published claims, and a claim is written by the deployer on every
 * CI deploy — so deriving an env-wide grant from it would let any app repo name
 * the env config bucket (or another environment's data) and have the next env
 * sync grant every observer access to it. A BYO bucket is reached through that
 * app's own per-app tier instead, whose document is built from the manifest under
 * an admin-run sync:app; the env tiers can assume every per-app role.
 */
class DataReadPolicy implements Deletable, Resource, SynchronisesConfiguration
{
    use ManagesCustomerPolicy;
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(Iam::DATA_READ_POLICY);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    public function description(): string
    {
        return 'YOLO managed read access to the app data buckets of this environment';
    }

    public function document(): array
    {
        $buckets = $this->dataBucketArns();

        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Resource' => $buckets,
                    'Action' => [
                        's3:ListBucket',
                        's3:GetBucketLocation',
                    ],
                ],
                [
                    'Effect' => 'Allow',
                    'Resource' => array_map(fn (string $arn): string => $arn . '/*', $buckets),
                    'Action' => [
                        's3:GetObject',
                        's3:GetObjectVersion',
                    ],
                ],
            ],
        ];
    }

    /**
     * Bucket ARNs, no object suffix.
     *
     * @return array<int, string>
     */
    protected function dataBucketArns(): array
    {
        return [Paths::s3EnvDataBucketsArn()];
    }
}
