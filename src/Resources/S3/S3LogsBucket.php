<?php

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Expiring telemetry, one prefix per log class (`alb/` today). Logs never share
 * a bucket with config/secrets: this bucket carries an external write principal
 * and a bucket-wide expiry. It owns the ELB log-delivery policy that
 * `ModifyLoadBalancerAttributes` validates, so it must exist before
 * `SyncLoadBalancerStep` — which is why it is env-scoped, not app: an
 * app-scoped bucket can't exist yet when the env-scoped ALB syncs (account →
 * environment → app), and a shared ALB points at exactly one
 * `access_logs.s3.bucket`, so per-app destinations would fight last-writer-wins.
 */
class S3LogsBucket implements Deletable, Resource, SynchronisesConfiguration
{
    use EmptiesBucket;
    use ReconcilesBucketHardening;
    use ResolvesTags;

    public function name(): string
    {
        return Paths::s3LogsBucket();
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        return S3::bucketExists($this->name());
    }

    public function arn(): string
    {
        return 'arn:aws:s3:::' . $this->name();
    }

    public function create(): void
    {
        Aws::s3()->createBucket([
            'Bucket' => $this->name(),
        ]);

        Aws::s3()->waitUntil('BucketExists', [
            'Bucket' => $this->name(),
        ]);

        $this->synchroniseTags(apply: true);
        $this->synchroniseConfiguration();
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseS3Tags($this->name(), $this->tags(), $apply);
    }

    public function synchroniseConfiguration(bool $apply = true): array
    {
        return [
            ...$this->reconcilePublicAccessBlock($apply),
            ...$this->reconcileVersioning($apply),
            ...$this->reconcileAccessLogDeliveryPolicy($apply),
            ...$this->reconcileLogExpiryLifecycle($apply),
        ];
    }

    public function delete(): void
    {
        try {
            $this->emptyVersions();

            S3::deleteBucket($this->name());
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return;
            }

            throw $e;
        }
    }

    /**
     * The log-delivery service principal (not a per-Region ELB account ID) is
     * correct for an SSE-S3 bucket. `aws:SourceAccount` + `aws:SourceArn` keep
     * the policy non-public so it coexists with `BlockPublicPolicy`; the `alb/*`
     * prefix keeps the principal inside its log class's namespace.
     *
     * @return array<int, Change>
     */
    protected function reconcileAccessLogDeliveryPolicy(bool $apply): array
    {
        $desired = $this->accessLogDeliveryPolicy();
        $current = S3::bucketPolicy($this->name());

        if (Helpers::documentsEqual($current, $desired)) {
            return [];
        }

        if ($apply) {
            Aws::s3()->putBucketPolicy([
                'Bucket' => $this->name(),
                'Policy' => json_encode($desired),
            ]);
        }

        return [Change::make('bucket-policy', $current === null ? null : 'present', 'alb-access-log-delivery')];
    }

    /**
     * Bucket-wide so any future log class inherits expiry by default.
     *
     * @return array<int, Change>
     */
    protected function reconcileLogExpiryLifecycle(bool $apply): array
    {
        // This schedules data for deletion and a naming bug would be silent for 90
        // days — the `-logs` suffix is the contract, so hard-fail if a refactor
        // wires this to any other bucket.
        if (! str_ends_with($this->name(), '-logs')) {
            throw new IntegrityCheckException(sprintf(
                'Refusing to apply the expiry lifecycle to "%s" — expiry only ever applies to a *-logs bucket.',
                $this->name(),
            ));
        }

        $desired = [
            [
                'ID' => 'expire-logs',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'Expiration' => ['Days' => 90],
                'NoncurrentVersionExpiration' => ['NoncurrentDays' => 7],
                'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 7],
            ],
        ];

        $current = S3::lifecycleRules($this->name());

        if (Helpers::documentsEqual($current, $desired)) {
            return [];
        }

        if ($apply) {
            Aws::s3()->putBucketLifecycleConfiguration([
                'Bucket' => $this->name(),
                'LifecycleConfiguration' => ['Rules' => $desired],
            ]);
        }

        return [Change::make('lifecycle', $current === null ? null : 'present', 'expire logs after 90 days')];
    }

    /**
     * @return array<string, mixed>
     */
    protected function accessLogDeliveryPolicy(): array
    {
        $accountId = Aws::accountId();

        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Sid' => 'AllowELBAccessLogDelivery',
                    'Effect' => 'Allow',
                    'Principal' => ['Service' => 'logdelivery.elasticloadbalancing.amazonaws.com'],
                    'Action' => 's3:PutObject',
                    'Resource' => $this->arn() . '/alb/*',
                    'Condition' => [
                        'StringEquals' => ['aws:SourceAccount' => $accountId],
                        'ArnLike' => [
                            'aws:SourceArn' => sprintf(
                                'arn:aws:elasticloadbalancing:%s:%s:loadbalancer/*',
                                Manifest::get('region'),
                                $accountId,
                            ),
                        ],
                    ],
                ],
            ],
        ];
    }
}
