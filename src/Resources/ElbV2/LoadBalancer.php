<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Resources\Ec2\LoadBalancerSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The application load balancer fronting the app's web tasks. Env-scoped, so
 * shared by default (auto-named yolo-{env}) — multiple apps in an environment
 * route off the one ALB via host-based listener rules — or pinned to a specific
 * name with `alb`.
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
        return Manifest::get('alb', $this->keyedName());
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

        // A fresh ALB sits in `provisioning` for a minute or two before it reaches
        // `active`. Later env-scope steps reference it the moment it exists — most
        // sharply SyncWafAssociationStep, whose associateWebACL throws
        // WAFUnavailableEntityException against a not-yet-active load balancer (a
        // bounded retry can't outwait a multi-minute provision). Block until it's
        // available so everything downstream sees a usable ALB; the LongRunning
        // sync step heartbeats the progress bar meanwhile.
        Aws::waitFor(Aws::elasticLoadBalancingV2(), 'LoadBalancerAvailable', [
            'LoadBalancerArns' => [$arn],
        ]);

        // A fresh ALB starts on AWS defaults (no deletion protection, access logs
        // off, invalid headers passed through); bring our hardened attributes onto
        // it. The env logs bucket (S3LogsBucket) is provisioned earlier in the
        // same scope, so enabling access logs validates against the log-delivery
        // bucket policy that already exists.
        $this->reconcileAttributes($arn, current: [], apply: true);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElbV2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Push the hardened attribute defaults onto an existing ALB. Tag sync doesn't
     * cover load-balancer attributes, so without this a changed default would never
     * reach an already-provisioned load balancer. Diffs first so a clean sync makes
     * no needless write, and returns the drifted attributes so sync can report them.
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $arn = $this->arn();

        return $this->reconcileAttributes($arn, $this->currentAttributes($arn), $apply);
    }

    /**
     * Batch every drifted attribute into a single modifyLoadBalancerAttributes
     * call (only when something drifted and $apply is set), and return the diff
     * as Change[] so the operator sees each current → desired comparison.
     *
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
            'access_logs.s3.bucket' => Paths::s3LogsBucket(),
            'access_logs.s3.prefix' => $this->accessLogsPrefix(),
            'routing.http.drop_invalid_header_fields.enabled' => 'true',
            'routing.http2.enabled' => 'true',
            'idle_timeout.timeout_seconds' => '60',
        ];
    }

    /**
     * Access logs land under alb/{name}/ in the env logs bucket — `alb/` is
     * the ALB log class's namespace (the delivery policy is scoped to it),
     * and the ALB name beneath keeps multiple ALBs (e.g. one shared, one
     * app-specific) cleanly separated. AWS appends /AWSLogs/{account}/...
     * beneath it.
     */
    public function accessLogsPrefix(): string
    {
        return sprintf('alb/%s', $this->name());
    }
}
