<?php

namespace Codinglabs\Yolo\Resources\WafV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
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
use Codinglabs\Yolo\Resources\CloudWatchLogs\WafLogGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * One regional ACL fronts every app on the environment ALB. It owns the policy
 * skeleton (default action, allow/block IP-set rules, managed groups, rate
 * limits); the high-churn list contents live in the create-only IP sets.
 * Reconciliation covers only the rules it owns (matched by Name), so a rule an
 * operator adds by hand survives every sync. Managed groups are referenced
 * unversioned so AWS's signature updates roll in on their own.
 */
class WebAcl implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    private const int RATE_LIMIT = 200;

    private const int RATE_WINDOW_SECONDS = 60;

    /**
     * Seeded once on create, then operator-owned (see seededRules()).
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
     * Public: the Typesense dashboard widget charts its blocks. Keystroke search
     * behind CGNAT makes the general per-IP ceiling a guaranteed false positive,
     * so the search host gets its own budget (~30-50 active searchers per IP).
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
        // The rules reference IP sets created moments earlier, which WAFv2 may not yet have propagated.
        $result = WafV2::retryWhileUnavailable(fn () => Aws::wafV2()->createWebACL([
            'Name' => $this->name(),
            'Scope' => WafV2::SCOPE,
            'Description' => 'YOLO managed WAF for the environment load balancer',
            'DefaultAction' => $this->defaultAction(),
            'Rules' => $this->creationRules(),
            'VisibilityConfig' => $this->visibilityConfig($this->name()),
            ...Aws::tags($this->tags()),
        ]));

        $this->reconcileLogging($result['Summary']['ARN'], apply: true);
    }

    /**
     * Skeleton plus seed-only rules; reconcile only ever touches desiredRules(),
     * so the seeds are laid down once and left alone.
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
     * A hardcoded starting point the operator then owns — like the empty IP sets,
     * but for a rule whose content can't live in a separate resource.
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
     * The destroy step disassociates the ACL from the ALB earlier, but that is
     * eventually consistent — so the delete retries past the transient
     * WAFAssociatedItemException.
     */
    public function delete(): void
    {
        try {
            $summary = WafV2::webAcl($this->name());
        } catch (ResourceDoesNotExistException) {
            return;
        }

        WafV2::retryWhileAssociated(fn () => Aws::wafV2()->deleteWebACL([
            'Name' => $this->name(),
            'Scope' => WafV2::SCOPE,
            'Id' => $summary['Id'],
            'LockToken' => $summary['LockToken'],
        ]));
    }

    /**
     * Drift is computed over the default action and YOLO-owned rules only; on
     * drift the whole set is rewritten as preserved human rules + desired rules,
     * the only update shape WAFv2 offers.
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

        // Loose `!=` on purpose: name-keyed maps of scalars compared regardless of
        // order — a strict `!==` would false-flag mere ordering differences.
        if ($this->ownedSignatures($liveRules) != $this->desiredSignatures()) {
            $changes[] = Change::make('rules', 'drift', 'reconciled (allow/block, managed groups, rate limit)');
        }

        if ($changes !== [] && $apply) {
            WafV2::retryWhileUnavailable(fn () => Aws::wafV2()->updateWebACL([
                'Name' => $this->name(),
                'Scope' => WafV2::SCOPE,
                'Id' => $summary['Id'],
                'LockToken' => $summary['LockToken'],
                'DefaultAction' => $this->defaultAction(),
                'Rules' => [...$this->preservedRules($liveRules), ...$this->desiredRules()],
                'VisibilityConfig' => $this->visibilityConfig($this->name()),
            ]));
        }

        return [...$changes, ...$this->reconcileLogging($summary['ARN'], $apply)];
    }

    /**
     * WAF writes the log-delivery resource policy onto the group itself on put,
     * so enabling logging is this one call. No RedactedFields — the kept slice is
     * the evidence stream.
     *
     * @return array<int, Change>
     */
    protected function reconcileLogging(string $webAclArn, bool $apply): array
    {
        $desiredDestination = (new WafLogGroup())->arn();
        $current = WafV2::loggingConfiguration($webAclArn);
        $currentDestination = $current['LogDestinationConfigs'][0] ?? null;

        if ($currentDestination === $desiredDestination
            && Helpers::documentsEqual($current['LoggingFilter'] ?? null, $this->loggingFilter())) {
            return [];
        }

        if ($apply) {
            WafV2::retryWhileLoggingPermissionsPropagate(fn () => Aws::wafV2()->putLoggingConfiguration([
                'LoggingConfiguration' => [
                    'ResourceArn' => $webAclArn,
                    'LogDestinationConfigs' => [$desiredDestination],
                    'LoggingFilter' => $this->loggingFilter(),
                ],
            ]));
        }

        return [Change::make('logging', $currentDestination, 'block+count → ' . $desiredDestination)];
    }

    /**
     * Allowed traffic is the bulk of the stream and already in the ALB access
     * logs; the block/count slice is what only WAF can explain. COUNT and
     * EXCLUDED_AS_COUNT are both kept — managed-group overrides surface under
     * either name.
     *
     * @return array<string, mixed>
     */
    public function loggingFilter(): array
    {
        return [
            'DefaultBehavior' => 'DROP',
            'Filters' => [[
                'Behavior' => 'KEEP',
                'Requirement' => 'MEETS_ANY',
                'Conditions' => [
                    ['ActionCondition' => ['Action' => 'BLOCK']],
                    ['ActionCondition' => ['Action' => 'COUNT']],
                    ['ActionCondition' => ['Action' => 'EXCLUDED_AS_COUNT']],
                ],
            ]],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function defaultAction(): array
    {
        return ['Allow' => []];
    }

    /**
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
     * Non-null only while the typesense service is provisioned; otherwise the
     * general rate rule covers everything and no search rule exists.
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
     * The Core Rule Set's SizeRestrictions_BODY sub-rule is dropped to Count: its
     * 8 KB request-body cap would block legitimate large POSTs that don't go
     * direct-to-S3 — a universal false-positive better observed than enforced.
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
                    // Carved out: the search host has its own roomier rule below.
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
     * @return array<int, string>
     */
    protected function ownedRuleNames(): array
    {
        return array_column($this->desiredRules(), 'Name');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function desiredSignatures(): array
    {
        return $this->signatures($this->desiredRules());
    }

    /**
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
     * A projection that survives echo-back, so drift detection doesn't trip over
     * fields AWS adds on read.
     *
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
