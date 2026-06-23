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
 * Env-scoped bucket holding expiring telemetry, one prefix per log class —
 * the shared ALB's access logs under `alb/` today; future log types (e.g.
 * WAF) join as sibling prefixes rather than new buckets. Logs never share a
 * bucket with config/secrets: this bucket carries an external write
 * principal and a bucket-wide expiry, both of which secrets must never sit
 * next to.
 *
 * Owns the ELB log-delivery bucket policy that `ModifyLoadBalancerAttributes`
 * validates when access logs are enabled on the load balancer — so the policy
 * needs to be in place *before* `SyncLoadBalancerStep` runs. It lives in the
 * env scope (not app) for two reasons:
 *
 *  1. **Ordering.** The shared `LoadBalancer` is env-scoped and writes its
 *     `access_logs.s3.bucket` attribute during the env scope's sync; an
 *     app-scoped log bucket can't exist yet (account → environment → app),
 *     so a greenfield sync's first ALB attribute write fails. Pulling the
 *     bucket up to env makes the policy precondition satisfiable.
 *  2. **Single-writer.** An env-shared ALB can only point at *one* bucket
 *     (`access_logs.s3.bucket` is a single value). A per-app log destination
 *     would mean apps fight over the attribute on a shared ALB —
 *     last-writer-wins. An env-scoped bucket aligns the destination's scope
 *     with the consumer's scope.
 *
 * Public-access-block (all four settings) and versioning are reconciled
 * declaratively; the bucket policy grants the `logdelivery.elasticloadbalancing.amazonaws.com`
 * service principal `s3:PutObject` over the `alb/` prefix only, scoped
 * to this account's load balancers via `aws:SourceAccount` and
 * `aws:SourceArn`. A bucket-wide lifecycle rule expires everything after 90
 * days — the bucket holds only append-only telemetry, so any future log
 * class inherits expiry by default. No `yolo:app` tag — env-scoped
 * (ResolvesTags handles that automatically).
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

    /**
     * Reconcile Block Public Access, versioning, the ELB log-delivery policy
     * and the log expiry lifecycle, each read-compared-then-written so
     * a clean sync is a no-op and a dry-run reports exactly what would change.
     * Returns the drifted attributes as Change[].
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        return [
            ...$this->reconcilePublicAccessBlock($apply),
            ...$this->reconcileVersioning($apply),
            ...$this->reconcileAccessLogDeliveryPolicy($apply),
            ...$this->reconcileLogExpiryLifecycle($apply),
        ];
    }

    /**
     * Empty then delete the bucket. S3 refuses DeleteBucket on a non-empty
     * bucket. This bucket is versioned (create() reconciles versioning to
     * Enabled), so emptying must clear every object version AND every delete
     * marker — a plain object sweep would leave noncurrent versions behind and
     * the delete would fail. The log-delivery bucket policy and the expiry
     * lifecycle are bucket-scoped, so they go with it — nothing else owns them.
     * A concurrent removal (NoSuchBucket / 404) is tolerated.
     */
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
     * Grant Elastic Load Balancing permission to deliver ALB access logs into
     * the bucket. Uses the log-delivery service principal (correct for the
     * SSE-S3-encrypted bucket; no customer-managed KMS key in play) rather
     * than a per-Region ELB account ID. `aws:SourceAccount` + `aws:SourceArn`
     * scope the grant to this account's load balancers, so the policy is
     * never public and coexists with `BlockPublicPolicy`. The grant is
     * prefix-scoped to `alb/*` — the delivery principal can never write
     * outside its log class's namespace.
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
     * Expire everything after 90 days — the bucket holds only append-only
     * telemetry, so the rule is bucket-wide and any future log class inherits
     * expiry by default. With versioning on, noncurrent copies are swept
     * shortly after, and abandoned multipart uploads are aborted.
     *
     * @return array<int, Change>
     */
    protected function reconcileLogExpiryLifecycle(bool $apply): array
    {
        // Paranoia gate: this is the one write in YOLO that schedules data
        // for deletion, and a naming bug would be silent for 90 days. The
        // class-based naming convention is the contract — a bucket's suffix
        // declares its handling — so an expiry lifecycle may only ever land
        // on a *-logs bucket. If a refactor wires this reconcile to anything
        // else (the config or assets helpers, an operator-named bucket),
        // hard-fail the sync rather than schedule that data for deletion.
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
