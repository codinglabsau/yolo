<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Steps\Sync\App\PublishAppManifestStep;

/**
 * Read access to the *data* the environment's apps hold — every app data bucket —
 * as its own document rather than a statement on {@see ObserverPolicy}. Infra
 * reads and data reads are separate axes: the per-app observer document is also
 * the CI deployer's fence, and a deploy grant must never be able to read user
 * uploads. Only the human tier roles carry this.
 *
 * The YOLO-named buckets sit inside the keyed `-data` wildcard; a bring-your-own
 * name comes from the app's published claim, which only sync:app writes (see
 * {@see PublishAppManifestStep} for why that matters).
 */
class DataReadPolicy implements Deletable, Resource, SynchronisesConfiguration
{
    use GrantsEnvDataBuckets;
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
}
