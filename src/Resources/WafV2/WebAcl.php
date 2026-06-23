<?php

namespace Codinglabs\Yolo\Resources\WafV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\WafV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\Deletable;
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
class WebAcl implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    /** Per-IP request ceiling, evaluated over a rolling 1-minute window. */
    private const int RATE_LIMIT = 200;

    private const int RATE_WINDOW_SECONDS = 60;

    /**
     * High-risk geographies blocked by default — a hardcoded starting point an
     * operator fine-tunes per app. Seeded once on create and then operator-owned
     * (see seededRules()), so edits survive every sync.
     *
     * @var array<int, string>
     */
    private const array BANNED_COUNTRIES = [
        'CN', 'GH', 'KP', 'LB', 'NG', 'RU', 'BD', 'NP', 'IQ', 'IR', 'CI',
    ];

    private const string ALLOW_RULE = 'yolo-allow-ips';

    private const string BLOCK_RULE = 'yolo-block-ips';

    private const string RATE_RULE = 'yolo-rate-limit';

    private const string COUNTRY_RULE = 'yolo-banned-countries';

    /**
     * The search host's own rate rule (public: the Typesense dashboard widget
     * charts its blocks). Keystroke-as-you-type search + CGNAT aggregation
     * make the general per-IP ceiling a guaranteed false positive, so the
     * search host is carved out of yolo-rate-limit and given its own budget —
     * roomy enough for ~30-50 simultaneously active searchers behind one IP.
     */
    public const string SEARCH_RATE_RULE = 'yolo-search-rate-limit';

    private const int SEARCH_RATE_LIMIT = 1000;

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
        // Retry on eventual consistency: the rules reference the allow/block IP
        // sets created moments earlier, which WAFv2 may not yet have propagated.
        WafV2::retryWhileUnavailable(fn () => Aws::wafV2()->createWebACL([
            'Name' => $this->name(),
            'Scope' => WafV2::SCOPE,
            'Description' => 'YOLO managed WAF for the environment load balancer',
            'DefaultAction' => $this->defaultAction(),
            'Rules' => $this->creationRules(),
            'VisibilityConfig' => $this->visibilityConfig($this->name()),
            ...Aws::tags($this->tags()),
        ]));
    }

    /**
     * The full rule set written at create time, priority-ordered: the reconciled
     * skeleton plus the seed-only rules (the country block) that are operator-owned
     * thereafter. Reconcile (synchroniseConfiguration) only ever touches
     * desiredRules(), so the seeds are laid down once and then left alone.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function creationRules(): array
    {
        return collect([...$this->desiredRules(), ...$this->seededRules()])
            ->sortBy('Priority')
            ->values()
            ->all();
    }

    /**
     * Rules YOLO seeds once on create and never reconciles — a hardcoded starting
     * point the operator then owns (like the empty allow/block IP sets, but for a
     * rule whose content can't live in a separate resource). The country block
     * lives here so an operator can re-scope it without sync reverting them.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function seededRules(): array
    {
        return [$this->bannedCountriesRule()];
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseWafV2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Teardown when the environment is torn down: delete the web ACL. WAFv2
     * needs the current LockToken (optimistic concurrency) and the Id, both read
     * from the live summary. The destroy step disassociates the ACL from the ALB
     * first — WAFv2 refuses to delete an ACL still associated with a resource —
     * so by the time we get here a plain deleteWebACL succeeds. A concurrent
     * removal (the summary lookup already 404s) is tolerated.
     */
    public function delete(): void
    {
        try {
            $summary = WafV2::webAcl($this->name());
        } catch (ResourceDoesNotExistException) {
            return;
        }

        Aws::wafV2()->deleteWebACL([
            'Name' => $this->name(),
            'Scope' => WafV2::SCOPE,
            'Id' => $summary['Id'],
            'LockToken' => $summary['LockToken'],
        ]);
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

        WafV2::retryWhileUnavailable(fn () => Aws::wafV2()->updateWebACL([
            'Name' => $this->name(),
            'Scope' => WafV2::SCOPE,
            'Id' => $summary['Id'],
            'LockToken' => $summary['LockToken'],
            'DefaultAction' => $this->defaultAction(),
            'Rules' => [...$this->preservedRules($liveRules), ...$this->desiredRules()],
            'VisibilityConfig' => $this->visibilityConfig($this->name()),
        ]));

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
            ...$this->searchHost() !== null ? [$this->searchRateLimitRule()] : [],
        ];
    }

    /**
     * The environment's search host, when its rate handling is active: the
     * env manifest declares a domain AND the typesense service is on
     * (the typesense service is declared in the env manifest). While inactive
     * the general rate rule covers
     * everything and no search rule exists.
     */
    protected function searchHost(): ?string
    {
        $host = Typesense::searchHost();

        if ($host === null) {
            return null;
        }

        return Lifecycle::state(Service::TYPESENSE) === ServiceState::Provision ? $host : null;
    }

    /**
     * AWS managed rule groups, referenced unversioned so they track the latest
     * signatures. Every group blocks (override None — the group's own Block actions
     * apply), with one carve-out: the Core Rule Set's SizeRestrictions_BODY sub-rule
     * is dropped to Count, because its 8 KB request-body cap would block legitimate
     * large POSTs that don't go direct-to-S3 — a universal false-positive we'd
     * rather observe than enforce. Per-sub-rule action overrides are declared here.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function managedGroupRules(): array
    {
        $groups = [
            ['name' => 'AWSManagedRulesAmazonIpReputationList', 'priority' => 10],
            ['name' => 'AWSManagedRulesKnownBadInputsRuleSet', 'priority' => 11],
            ['name' => 'AWSManagedRulesCommonRuleSet', 'priority' => 12, 'ruleOverrides' => ['SizeRestrictions_BODY' => 'Count']],
            ['name' => 'AWSManagedRulesSQLiRuleSet', 'priority' => 13],
            ['name' => 'AWSManagedRulesPHPRuleSet', 'priority' => 14],
        ];

        return array_map(fn (array $group): array => [
            'Name' => 'AWS-' . $group['name'],
            'Priority' => $group['priority'],
            'OverrideAction' => ['None' => []],
            'Statement' => [
                'ManagedRuleGroupStatement' => array_filter([
                    'VendorName' => 'AWS',
                    'Name' => $group['name'],
                    'RuleActionOverrides' => $this->ruleActionOverrides($group['ruleOverrides'] ?? []),
                ]),
            ],
            'VisibilityConfig' => $this->visibilityConfig('AWS-' . $group['name']),
        ], $groups);
    }

    /**
     * Translate a [sub-rule => action] map into the WAFv2 RuleActionOverrides shape.
     * Empty when a group has no carve-outs (dropped by array_filter on the caller).
     *
     * @param  array<string, string>  $overrides
     * @return array<int, array<string, mixed>>
     */
    protected function ruleActionOverrides(array $overrides): array
    {
        return collect($overrides)
            ->map(fn (string $action, string $name): array => ['Name' => $name, 'ActionToUse' => [$action => []]])
            ->values()
            ->all();
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
                    'EvaluationWindowSec' => self::RATE_WINDOW_SECONDS,
                    // The search host is carved out — it has its own roomier
                    // rule below, so keystroke search never trips the general
                    // per-IP ceiling.
                    ...$this->searchHost() !== null ? [
                        'ScopeDownStatement' => [
                            'NotStatement' => ['Statement' => $this->searchHostStatement()],
                        ],
                    ] : [],
                ],
            ],
            'VisibilityConfig' => $this->visibilityConfig(self::RATE_RULE),
        ];
    }

    /**
     * The search host's own per-IP rate rule, scoped to host == search.{domain}.
     *
     * @return array<string, mixed>
     */
    protected function searchRateLimitRule(): array
    {
        return [
            'Name' => self::SEARCH_RATE_RULE,
            'Priority' => 21,
            'Action' => ['Block' => []],
            'Statement' => [
                'RateBasedStatement' => [
                    'Limit' => self::SEARCH_RATE_LIMIT,
                    'AggregateKeyType' => 'IP',
                    'EvaluationWindowSec' => self::RATE_WINDOW_SECONDS,
                    'ScopeDownStatement' => $this->searchHostStatement(),
                ],
            ],
            'VisibilityConfig' => $this->visibilityConfig(self::SEARCH_RATE_RULE),
        ];
    }

    /**
     * host-header == the search host, lowercased exact match.
     *
     * @return array<string, mixed>
     */
    protected function searchHostStatement(): array
    {
        return [
            'ByteMatchStatement' => [
                'FieldToMatch' => ['SingleHeader' => ['Name' => 'host']],
                'PositionalConstraint' => 'EXACTLY',
                'SearchString' => (string) $this->searchHost(),
                'TextTransformations' => [['Priority' => 0, 'Type' => 'LOWERCASE']],
            ],
        ];
    }

    /**
     * The default country block (seed-only — see seededRules()). Action Block, a
     * geo-match on the hardcoded high-risk list; the operator re-scopes the
     * countries afterwards and sync never reverts them.
     *
     * @return array<string, mixed>
     */
    protected function bannedCountriesRule(): array
    {
        return [
            'Name' => self::COUNTRY_RULE,
            'Priority' => 2,
            'Action' => ['Block' => []],
            'Statement' => [
                'GeoMatchStatement' => ['CountryCodes' => self::BANNED_COUNTRIES],
            ],
            'VisibilityConfig' => $this->visibilityConfig(self::COUNTRY_RULE),
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
