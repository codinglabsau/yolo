<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Resources\Ec2\LoadBalancerSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class LoadBalancer implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName();
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            ElbV2::loadBalancer($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ElbV2::loadBalancer($this->name())['LoadBalancerArn'];
    }

    public function create(): void
    {
        $arn = Aws::elasticLoadBalancingV2()->createLoadBalancer([
            'Name' => $this->name(),
            'Type' => 'application',
            'Scheme' => 'internet-facing',
            'IpAddressType' => 'ipv4',
            'SecurityGroups' => [
                (new LoadBalancerSecurityGroup())->arn(),
            ],
            'Subnets' => PublicSubnet::ids(),
            ...Aws::tags($this->tags()),
        ])['LoadBalancers'][0]['LoadBalancerArn'];

        // A fresh ALB sits in `provisioning` for a minute or two; downstream env
        // steps need it `active` (SyncWafAssociationStep's associateWebACL throws
        // WAFUnavailableEntityException otherwise, and a bounded retry can't outwait it).
        Aws::waitFor(Aws::elasticLoadBalancingV2(), 'LoadBalancerAvailable', [
            'LoadBalancerArns' => [$arn],
        ]);

        // Enabling access logs validates against the logs bucket's delivery policy,
        // which S3LogsBucket provisions earlier in the same scope.
        $this->reconcileAttributes($arn, current: [], apply: true);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElbV2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Deletion protection is pinned on by `desiredAttributes`; AWS rejects
     * deleteLoadBalancer with OperationNotPermitted until it's lifted.
     */
    public function delete(): void
    {
        try {
            $arn = $this->arn();

            Aws::elasticLoadBalancingV2()->modifyLoadBalancerAttributes([
                'LoadBalancerArn' => $arn,
                'Attributes' => [['Key' => 'deletion_protection.enabled', 'Value' => 'false']],
            ]);

            Aws::elasticLoadBalancingV2()->deleteLoadBalancer([
                'LoadBalancerArn' => $arn,
            ]);
        } catch (ResourceDoesNotExistException) {
            // arn() resolution raced a concurrent delete — already gone.
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'LoadBalancerNotFound') {
                return;
            }

            throw $e;
        }
    }

    public function synchroniseConfiguration(bool $apply = true): array
    {
        $arn = $this->arn();

        return $this->reconcileAttributes($arn, $this->currentAttributes($arn), $apply);
    }

    /**
     * @param  array<string, string>  $current  live attributes (empty on create)
     * @return array<int, Change>
     */
    protected function reconcileAttributes(string $arn, array $current, bool $apply): array
    {
        $desired = $this->desiredAttributes();

        $changes = collect($desired)
            ->filter(fn (string $value, string $key): bool => ($current[$key] ?? null) !== $value)
            ->map(fn (string $value, string $key): Change => Change::make($key, $current[$key] ?? null, $value))
            ->values()
            ->all();

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        Aws::elasticLoadBalancingV2()->modifyLoadBalancerAttributes([
            'LoadBalancerArn' => $arn,
            'Attributes' => collect($desired)
                ->map(fn (string $value, string $key): array => ['Key' => $key, 'Value' => $value])
                ->values()
                ->all(),
        ]);

        return $changes;
    }

    /**
     * @return array<string, string>
     */
    protected function currentAttributes(string $arn): array
    {
        return collect(
            Aws::elasticLoadBalancingV2()->describeLoadBalancerAttributes([
                'LoadBalancerArn' => $arn,
            ])['Attributes']
        )
            ->mapWithKeys(fn (array $attribute): array => [$attribute['Key'] => $attribute['Value']])
            ->all();
    }

    /**
     * Deliberately not manifest-configurable: deletion protection is always on
     * (teardown lifts it deterministically), access logs and dropped invalid
     * headers are always-correct hardening, and HTTP/2 + the 60s idle timeout are
     * pinned to AWS's own defaults so they can't silently drift.
     *
     * @return array<string, string>
     */
    public function desiredAttributes(): array
    {
        return [
            'deletion_protection.enabled' => 'true',
            'access_logs.s3.enabled' => 'true',
            'access_logs.s3.bucket' => Paths::s3LogsBucket(),
            'access_logs.s3.prefix' => $this->accessLogsPrefix(),
            'routing.http.drop_invalid_header_fields.enabled' => 'true',
            'routing.http2.enabled' => 'true',
            'idle_timeout.timeout_seconds' => '60',
        ];
    }

    /**
     * `alb/` is the namespace the logs bucket's delivery policy is scoped to; the
     * ALB name beneath keeps multiple ALBs apart. AWS appends /AWSLogs/{account}/….
     */
    public function accessLogsPrefix(): string
    {
        return sprintf('alb/%s', $this->name());
    }
}
