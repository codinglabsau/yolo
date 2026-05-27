<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Network\PublicSubnet;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\Network\LoadBalancerSecurityGroup;

/**
 * The application load balancer fronting the app's web tasks. Env-scoped, so
 * shared by default (auto-named yolo-{env}) — multiple apps in an environment
 * route off the one ALB via host-based listener rules — or pinned to a specific
 * name with `aws.alb`.
 *
 * Beyond identity + tags, this resource also owns the ALB's hardened attribute
 * defaults (deletion protection, access logs, dropped invalid headers, idle
 * timeout, HTTP/2) and reconciles them onto an existing ALB via
 * SynchronisesConfiguration so a changed default reaches an already-provisioned
 * load balancer.
 */
class LoadBalancer implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get('aws.alb', $this->keyedName());
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

        // A fresh ALB starts on AWS defaults (no deletion protection, access logs
        // off, invalid headers passed through); bring our hardened attributes onto
        // it. The artefacts bucket access-logs policy is provisioned earlier in the
        // sync (Storage runs before Compute), so enabling access logs validates.
        $this->reconcileAttributes($arn, current: []);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseElbV2Tags($this->arn(), $this->tags());
    }

    /**
     * Push the hardened attribute defaults onto an existing ALB. Tag sync doesn't
     * cover load-balancer attributes, so without this a changed default would never
     * reach an already-provisioned load balancer. Diffs first so a clean sync makes
     * no needless write.
     */
    public function synchroniseConfiguration(): void
    {
        $arn = $this->arn();

        $this->reconcileAttributes($arn, $this->currentAttributes($arn));
    }

    /**
     * Batch every managed attribute into a single modifyLoadBalancerAttributes
     * call, but only when at least one has drifted from the desired value.
     *
     * @param  array<string, string>  $current  live attributes (empty on create)
     */
    protected function reconcileAttributes(string $arn, array $current): void
    {
        $desired = $this->desiredAttributes();

        $drifted = collect($desired)
            ->contains(fn (string $value, string $key) => ($current[$key] ?? null) !== $value);

        if (! $drifted) {
            return;
        }

        Aws::elasticLoadBalancingV2()->modifyLoadBalancerAttributes([
            'LoadBalancerArn' => $arn,
            'Attributes' => collect($desired)
                ->map(fn (string $value, string $key) => ['Key' => $key, 'Value' => $value])
                ->values()
                ->all(),
        ]);
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
            ->mapWithKeys(fn (array $attribute) => [$attribute['Key'] => $attribute['Value']])
            ->all();
    }

    /**
     * The full set of ALB attributes YOLO manages, as the string key/value pairs
     * the ELBv2 API expects. One source of truth shared by create and sync so the
     * two paths can't drift apart.
     *
     * These are hardcoded sensible defaults, deliberately not manifest-configurable:
     * deletion protection is always on (a future destroy command lifts it
     * deterministically before deleting); access logs and dropped invalid headers
     * are always-correct hardening; HTTP/2 and the 60s idle timeout are pinned to
     * AWS's own defaults so they can't silently drift. Anything that turns out to
     * need tuning can earn a manifest knob in a later release.
     *
     * @return array<string, string>
     */
    public function desiredAttributes(): array
    {
        return [
            'deletion_protection.enabled' => 'true',
            'access_logs.s3.enabled' => 'true',
            'access_logs.s3.bucket' => Paths::s3ArtefactsBucket(),
            'access_logs.s3.prefix' => $this->accessLogsPrefix(),
            'routing.http.drop_invalid_header_fields.enabled' => 'true',
            'routing.http2.enabled' => 'true',
            'idle_timeout.timeout_seconds' => '60',
        ];
    }

    /**
     * Access logs land under alb-access-logs/{env}/{name}/ in the artefacts
     * bucket, so a shared ALB's logs are grouped by its name and split from any
     * other yolo-managed objects. AWS appends /AWSLogs/{account}/... beneath it.
     */
    public function accessLogsPrefix(): string
    {
        return sprintf('alb-access-logs/%s/%s', Helpers::environment(), $this->name());
    }
}
