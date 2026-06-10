<?php

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Env-scoped general-purpose bucket shared by every app in the environment.
 * Each env-tier object class lives under its own prefix — the shared ALB's
 * access logs under `alb-logs/` today; future env-tier objects join as
 * sibling prefixes rather than new buckets.
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
 * service principal `s3:PutObject` over the `alb-logs/` prefix only, scoped
 * to this account's load balancers via `aws:SourceAccount` and
 * `aws:SourceArn`. A prefix-scoped lifecycle rule expires the access logs —
 * append-only telemetry that would otherwise accumulate forever. No
 * `yolo:app` tag — env-scoped (ResolvesTags handles that automatically).
 */
class S3EnvironmentBucket implements Resource, SynchronisesConfiguration
{
    use ReconcilesBucketHardening;
    use ResolvesTags;

    public function name(): string
    {
        return Paths::s3EnvironmentBucket();
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
     * and the access-log expiry lifecycle, each read-compared-then-written so
     * a clean sync is a no-op and a dry-run reports exactly what would change.
     * Returns the drifted attributes as Change[].
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        return [
            ...$this->reconcilePublicAccessBlock($apply),
            ...$this->reconcileVersioning($apply),
            ...$this->reconcileAccessLogDeliveryPolicy($apply),
            ...$this->reconcileAccessLogLifecycle($apply),
        ];
    }

    /**
     * Grant Elastic Load Balancing permission to deliver ALB access logs into
     * the bucket. Uses the log-delivery service principal (correct for the
     * SSE-S3-encrypted bucket; no customer-managed KMS key in play) rather
     * than a per-Region ELB account ID. `aws:SourceAccount` + `aws:SourceArn`
     * scope the grant to this account's load balancers, so the policy is
     * never public and coexists with `BlockPublicPolicy`. The bucket is
     * shared by multiple object classes, so the grant is prefix-scoped to
     * `alb-logs/*` — the delivery principal can never write outside its
     * namespace.
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
     * Expire ALB access logs after 90 days — with versioning on, noncurrent
     * copies are swept shortly after, and abandoned multipart uploads are
     * aborted. The rule is prefix-scoped so it can never touch a future
     * sibling prefix.
     *
     * @return array<int, Change>
     */
    protected function reconcileAccessLogLifecycle(bool $apply): array
    {
        $desired = [
            [
                'ID' => 'expire-alb-logs',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => 'alb-logs/'],
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

        return [Change::make('lifecycle', $current === null ? null : 'present', 'expire alb-logs/ after 90 days')];
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
                    'Resource' => sprintf('arn:aws:s3:::%s/alb-logs/*', $this->name()),
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
