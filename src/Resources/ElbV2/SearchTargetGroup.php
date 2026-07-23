<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The env-shared target group fronting all the Typesense nodes on the search
 * API port — browser traffic enters here via the `search.{domain}` listener
 * rule; each node's ECS service registers its task IP.
 *
 * The health check tests process LIVENESS, deliberately not raft health: this
 * check feeds ECS task replacement, so whatever it tests is what ECS executes
 * a node for. Typesense's own /health answers 503 on a node that has merely
 * lost quorum or is catching up — a state a node recovers FROM, and one a
 * mid-roll cluster passes THROUGH — so checking /health turns a degraded node
 * into a killed node, and a killed node into an empty ephemeral disk; one
 * sick node can cascade into a dead cluster. An ALB target group can't run a
 * plain TCP probe (that's the NLB's trick) and its matcher tops out at 499,
 * so liveness is expressed as HTTP: an unauthenticated hit on an admin route,
 * which a live node always answers with 401 — quorum or no quorum. Routing a
 * degraded node's 503s to searchers is an accepted cost; executing its disk
 * is not. Rollout readiness is the node sync step's roll gate now, not the
 * ALB's.
 */
class SearchTargetGroup implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    /**
     * An admin route, so the check never depends on collection state; the ALB
     * sends no API key, so a live node always answers 401 — the liveness
     * signal.
     */
    public const string HEALTH_CHECK_PATH = '/keys';

    public const string HEALTH_CHECK_MATCHER = '200-499';

    public function name(): string
    {
        return $this->keyedName('search');
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            ElbV2::targetGroup($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ElbV2::targetGroup($this->name())['TargetGroupArn'];
    }

    public function create(): void
    {
        $arn = Aws::elasticLoadBalancingV2()->createTargetGroup([
            'Name' => $this->name(),
            'Protocol' => 'HTTP',
            'Port' => Typesense::API_PORT,
            'TargetType' => 'ip',
            'VpcId' => (new Vpc())->arn(),
            'HealthCheckProtocol' => 'HTTP',
            'HealthCheckPath' => self::HEALTH_CHECK_PATH,
            'HealthCheckIntervalSeconds' => 15,
            'HealthyThresholdCount' => 2,
            'UnhealthyThresholdCount' => 3,
            'Matcher' => ['HttpCode' => self::HEALTH_CHECK_MATCHER],
            ...Aws::tags($this->tags()),
        ])['TargetGroups'][0]['TargetGroupArn'];

        // Search requests are short-lived — a draining node doesn't need the
        // 300s default to finish in-flight queries.
        Aws::elasticLoadBalancingV2()->modifyTargetGroupAttributes([
            'TargetGroupArn' => $arn,
            'Attributes' => [
                ['Key' => 'deregistration_delay.timeout_seconds', 'Value' => '10'],
            ],
        ]);
    }

    /**
     * Converge an existing target group's health check onto the liveness
     * shape — an environment provisioned when the check still tested /health
     * keeps the node-killing check until this pushes the change.
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $live = ElbV2::targetGroup($this->name());

        $changes = [];

        if (($live['HealthCheckPath'] ?? null) !== self::HEALTH_CHECK_PATH) {
            $changes[] = Change::make('health-check-path', $live['HealthCheckPath'] ?? null, self::HEALTH_CHECK_PATH);
        }

        if (($live['Matcher']['HttpCode'] ?? null) !== self::HEALTH_CHECK_MATCHER) {
            $changes[] = Change::make('health-check-matcher', $live['Matcher']['HttpCode'] ?? null, self::HEALTH_CHECK_MATCHER);
        }

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        Aws::elasticLoadBalancingV2()->modifyTargetGroup([
            'TargetGroupArn' => $live['TargetGroupArn'],
            'HealthCheckPath' => self::HEALTH_CHECK_PATH,
            'Matcher' => ['HttpCode' => self::HEALTH_CHECK_MATCHER],
        ]);

        return $changes;
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElbV2Tags($this->arn(), $this->tags(), $apply);
    }

    public function delete(): void
    {
        Aws::elasticLoadBalancingV2()->deleteTargetGroup([
            'TargetGroupArn' => $this->arn(),
        ]);
    }
}
