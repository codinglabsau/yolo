<?php

namespace Codinglabs\Yolo\Resources\WafV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\WafV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The env-shared regional WAF web ACL associated with the environment ALB — one
 * ACL fronts every app sharing the load balancer. It owns the *policy skeleton*:
 * the default action, the allow/block IP-set rules, the AWS managed rule groups
 * and the per-IP rate limit. The high-churn list *contents* live in AllowIpSet /
 * BlockIpSet (create-only); this resource only wires those sets in by reference.
 *
 * As a SynchronisesConfiguration it reconciles that skeleton onto an existing ACL,
 * but only over the rules it owns (matched by Name): a rule an operator adds by
 * hand is preserved through every sync, mirroring the listener-rule ownership
 * model. The managed groups are referenced *unversioned* so AWS's signature and
 * IP-reputation updates roll in on their own — the noisy groups (CRS, SQLi) ship
 * in Count so a new AWS signature can't start blocking live traffic unannounced;
 * the low-false-positive groups block outright.
 */
class WebAcl implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    /** Per-IP request ceiling over the rolling 5-minute window. */
    private const int RATE_LIMIT = 2000;

    private const string ALLOW_RULE = 'yolo-allow-ips';

    private const string BLOCK_RULE = 'yolo-block-ips';

    private const string RATE_RULE = 'yolo-rate-limit';

    public function name(): string
    {
        return $this->keyedName('waf');
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            WafV2::webAcl($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return WafV2::webAcl($this->name())['ARN'];
    }

    public function create(): void
    {
        Aws::wafV2()->createWebACL([
            'Name' => $this->name(),
            'Scope' => WafV2::SCOPE,
            'Description' => 'YOLO managed WAF for the environment load balancer',
            'DefaultAction' => $this->defaultAction(),
            'Rules' => $this->desiredRules(),
            'VisibilityConfig' => $this->visibilityConfig($this->name()),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseWafV2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Reconcile the policy skeleton onto the live ACL. Drift is computed over the
     * default action and the YOLO-owned rules only (by Name) — a hand-added rule
     * is invisible to the diff and survives the write. On drift the whole rule set
     * is rewritten as (preserved human rules + desired YOLO rules), which is the
     * only update shape WAFv2 offers.
     *
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $summary = WafV2::webAcl($this->name());
        $live = Aws::wafV2()->getWebACL([
            'Name' => $this->name(),
            'Scope' => WafV2::SCOPE,
            'Id' => $summary['Id'],
        ]);

        $liveRules = $live['WebACL']['Rules'] ?? [];
        $changes = [];

        $liveDefault = array_key_first($live['WebACL']['DefaultAction'] ?? []);

        if ($liveDefault !== 'Allow') {
            $changes[] = Change::make('default-action', $liveDefault, 'Allow');
        }

        // Loose `!=` on purpose: both sides are name-keyed maps of scalar
        // signatures, so this compares key/value pairs regardless of order (a
        // strict `!==` would false-flag drift on mere ordering differences).
        if ($this->ownedSignatures($liveRules) != $this->desiredSignatures()) {
            $changes[] = Change::make('rules', 'drift', 'reconciled (allow/block, managed groups, rate limit)');
        }

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        Aws::wafV2()->updateWebACL([
            'Name' => $this->name(),
            'Scope' => WafV2::SCOPE,
            'Id' => $summary['Id'],
            'LockToken' => $summary['LockToken'],
            'DefaultAction' => $this->defaultAction(),
            'Rules' => [...$this->preservedRules($liveRules), ...$this->desiredRules()],
            'VisibilityConfig' => $this->visibilityConfig($this->name()),
        ]);

        return $changes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function defaultAction(): array
    {
        return ['Allow' => []];
    }

    /**
     * The complete desired rule set, in priority order: the operator allow list,
     * the operator block list, the AWS managed groups, then the rate limit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function desiredRules(): array
    {
        $allowArn = (new AllowIpSet())->arn();
        $blockArn = (new BlockIpSet())->arn();

        return [
            $this->ipSetRule(self::ALLOW_RULE, 0, $allowArn, action: 'Allow'),
            $this->ipSetRule(self::BLOCK_RULE, 1, $blockArn, action: 'Block'),
            ...$this->managedGroupRules(),
            $this->rateLimitRule(),
        ];
    }

    /**
     * AWS managed rule groups, referenced unversioned so they track the latest
     * signatures. The low-false-positive groups override to None (the group's own
     * Block actions apply); CRS and SQLi override to Count so they observe without
     * blocking until an operator promotes them.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function managedGroupRules(): array
    {
        $groups = [
            ['name' => 'AWSManagedRulesAmazonIpReputationList', 'priority' => 10, 'override' => 'None'],
            ['name' => 'AWSManagedRulesKnownBadInputsRuleSet', 'priority' => 11, 'override' => 'None'],
            ['name' => 'AWSManagedRulesCommonRuleSet', 'priority' => 12, 'override' => 'Count'],
            ['name' => 'AWSManagedRulesSQLiRuleSet', 'priority' => 13, 'override' => 'Count'],
        ];

        return array_map(fn (array $group): array => [
            'Name' => 'AWS-' . $group['name'],
            'Priority' => $group['priority'],
            'OverrideAction' => [$group['override'] => []],
            'Statement' => [
                'ManagedRuleGroupStatement' => [
                    'VendorName' => 'AWS',
                    'Name' => $group['name'],
                ],
            ],
            'VisibilityConfig' => $this->visibilityConfig('AWS-' . $group['name']),
        ], $groups);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ipSetRule(string $name, int $priority, string $arn, string $action): array
    {
        return [
            'Name' => $name,
            'Priority' => $priority,
            'Action' => [$action => []],
            'Statement' => [
                'IPSetReferenceStatement' => ['ARN' => $arn],
            ],
            'VisibilityConfig' => $this->visibilityConfig($name),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rateLimitRule(): array
    {
        return [
            'Name' => self::RATE_RULE,
            'Priority' => 20,
            'Action' => ['Block' => []],
            'Statement' => [
                'RateBasedStatement' => [
                    'Limit' => self::RATE_LIMIT,
                    'AggregateKeyType' => 'IP',
                ],
            ],
            'VisibilityConfig' => $this->visibilityConfig(self::RATE_RULE),
        ];
    }

    /**
     * Live rules YOLO doesn't own (matched by Name) — preserved verbatim through
     * an update so an operator's hand-rolled rules are never clobbered.
     *
     * @param  array<int, array<string, mixed>>  $liveRules
     * @return array<int, array<string, mixed>>
     */
    protected function preservedRules(array $liveRules): array
    {
        $owned = $this->ownedRuleNames();

        return array_values(array_filter(
            $liveRules,
            fn (array $rule): bool => ! in_array($rule['Name'], $owned, true),
        ));
    }

    /**
     * The Names of every rule YOLO manages.
     *
     * @return array<int, string>
     */
    protected function ownedRuleNames(): array
    {
        return array_column($this->desiredRules(), 'Name');
    }

    /**
     * A stable, echo-back-proof projection of the desired YOLO rules, keyed by
     * Name — used to detect drift without tripping over fields AWS adds on read.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function desiredSignatures(): array
    {
        return $this->signatures($this->desiredRules());
    }

    /**
     * The same projection over the live rules YOLO owns (others are ignored).
     *
     * @param  array<int, array<string, mixed>>  $liveRules
     * @return array<string, array<string, mixed>>
     */
    protected function ownedSignatures(array $liveRules): array
    {
        $owned = $this->ownedRuleNames();

        return $this->signatures(array_filter(
            $liveRules,
            fn (array $rule): bool => in_array($rule['Name'], $owned, true),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, array<string, mixed>>
     */
    protected function signatures(array $rules): array
    {
        $signatures = [];

        foreach ($rules as $rule) {
            $signatures[$rule['Name']] = [
                'priority' => $rule['Priority'],
                'statement' => $this->statementSignature($rule['Statement']),
                'action' => $this->actionSignature($rule),
            ];
        }

        return $signatures;
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    protected function statementSignature(array $statement): string
    {
        return match (true) {
            isset($statement['ManagedRuleGroupStatement']) => 'managed:'
                . $statement['ManagedRuleGroupStatement']['VendorName'] . ':'
                . $statement['ManagedRuleGroupStatement']['Name'],
            isset($statement['IPSetReferenceStatement']) => 'ipset:'
                . $statement['IPSetReferenceStatement']['ARN'],
            isset($statement['RateBasedStatement']) => 'rate:'
                . $statement['RateBasedStatement']['Limit'] . ':'
                . $statement['RateBasedStatement']['AggregateKeyType'],
            default => json_encode($statement),
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    protected function actionSignature(array $rule): string
    {
        if (isset($rule['OverrideAction'])) {
            return 'override:' . array_key_first($rule['OverrideAction']);
        }

        if (isset($rule['Action'])) {
            return 'action:' . array_key_first($rule['Action']);
        }

        return 'none';
    }

    /**
     * @return array<string, mixed>
     */
    protected function visibilityConfig(string $metricName): array
    {
        return [
            'SampledRequestsEnabled' => true,
            'CloudWatchMetricsEnabled' => true,
            'MetricName' => $metricName,
        ];
    }
}
