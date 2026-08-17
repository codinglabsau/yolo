<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Base for an app's HTTPS listener rules. Identity is the rule's stable `Name`
 * tag — `yolo-{env}-{app}` for the forward rule, `yolo-{env}-{app}-redirect` for
 * the redirect rule — NOT the hosts it routes. The host-set is configuration that
 * a domain change rewrites in place (see synchroniseConfiguration); keying
 * identity off the hosts instead would make a domain change look like a brand-new
 * rule (orphaning the old one), and an apex↔www swap match the *other* rule. Name
 * identity means sync only ever finds and mutates this app's own rule — a
 * sibling host's rule (another app, or a hand-rolled `custom.domain.com`) has a
 * different Name and is never touched.
 *
 * Concrete rules supply their host-set and action: a {@see ForwardListenerRule}
 * forwards the canonical host to the target group; a {@see RedirectListenerRule}
 * 301-redirects the apex/`www` sibling to the canonical host.
 */
abstract class ListenerRule implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    /**
     * Priority bands, by rule kind. ALB evaluates rules lowest-priority-number
     * first, so a rule matching an exact host must sit below any rule that can
     * match it by wildcard: a wildcard-subdomain app's `*.{apex}` forward rule
     * also matches `www.{apex}` and `search.{apex}`, and whichever rule drew the
     * lower number would win. Banding makes that ordering deterministic instead
     * of a hash race — the apex/www redirect outranks every forward rule, and an
     * exact-host forward rule (an env service's `search.{domain}`, a sibling
     * app's host) outranks any wildcard-carrying forward rule, so a wildcard
     * only ever catches hosts nothing else claims.
     *
     * Within a band the number is still a stable hash of the rule name, so two
     * apps never collide and a rule keeps its priority across syncs.
     */
    protected const PRIORITY_BANDS = [
        'redirect' => [1000, 9999],
        'forward' => [10000, 29999],
        'wildcard' => [30000, 49999],
    ];

    protected ?array $cachedRule = null;

    public function __construct(protected string $httpsListenerArn) {}

    /**
     * The host headers this rule matches.
     *
     * @return array<int, string>
     */
    abstract public function hosts(): array;

    /**
     * The rule's desired ELBv2 action payload (forward / redirect).
     *
     * @return array<string, mixed>
     */
    abstract protected function action(): array;

    /**
     * A Change when the live action differs from this rule's desired action
     * (e.g. an apex↔www swap left a forward rule where a redirect belongs), else
     * null. Subclasses compare only the fields they set.
     *
     * @param  array<string, mixed>  $liveAction
     */
    abstract protected function actionDrift(array $liveAction): ?Change;

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        return $this->find() !== null;
    }

    public function arn(): string
    {
        return $this->find()['RuleArn'];
    }

    public function create(): void
    {
        Aws::elasticLoadBalancingV2()->createRule([
            'ListenerArn' => $this->httpsListenerArn,
            'Priority' => $this->priority(),
            'Conditions' => [$this->hostCondition()],
            'Actions' => [$this->action()],
            ...Aws::tags($this->tags()),
        ]);

        $this->cachedRule = null;
    }

    /**
     * Teardown removes only this app's own rule, found by its stable Name tag on
     * the shared `:443` listener — never the listener itself (env-scope, shared by
     * every app) nor any sibling host's rule. This runs ahead of the target
     * group's delete in the teardown order, so the forward action's reference to
     * the group is gone before the group is removed. A concurrent not-found is
     * tolerated: find() simply returns null and there's nothing to do.
     */
    public function delete(): void
    {
        if ($rule = $this->find()) {
            ElbV2::deleteRule($rule['RuleArn']);

            $this->cachedRule = null;
        }
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElbV2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Reconcile the live rule's host conditions, action and priority band onto
     * the desired ones, in place — so changing `domain` (apex↔www, or to a
     * different host) rewrites this app's existing rule rather than orphaning it
     * and creating a new one, and flipping `wildcard-subdomains` moves the rule
     * into the band its new host-set demands. Only this rule (found by Name) is
     * ever modified.
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $rule = $this->find();

        if ($rule === null) {
            return [];
        }

        $changes = [];
        $liveHosts = $this->liveHosts($rule);

        if (! $this->sameHosts($liveHosts, $this->hosts())) {
            $changes[] = Change::make('host-header', implode(', ', $liveHosts), implode(', ', $this->hosts()));
        }

        if (($actionChange = $this->actionDrift($rule['Actions'][0] ?? [])) instanceof Change) {
            $changes[] = $actionChange;
        }

        if ($changes !== [] && $apply) {
            Aws::elasticLoadBalancingV2()->modifyRule([
                'RuleArn' => $rule['RuleArn'],
                'Conditions' => [$this->hostCondition()],
                'Actions' => [$this->action()],
            ]);

            $this->cachedRule = null;
        }

        if (($priorityChange = $this->reconcilePriority($rule, $apply)) instanceof Change) {
            $changes[] = $priorityChange;
        }

        return $changes;
    }

    /**
     * A Change (applied via SetRulePriorities when $apply) when the live rule
     * sits outside its band, else null. The band is derived from the DESIRED
     * host-set, so this both migrates rules created before the forward band was
     * split and moves a rule whose band changed with its hosts — without it, the
     * banding above would only ever govern freshly-created rules.
     */
    protected function reconcilePriority(array $rule, bool $apply): ?Change
    {
        $livePriority = (int) $rule['Priority'];
        [$floor, $ceiling] = static::PRIORITY_BANDS[$this->band()];

        if ($livePriority >= $floor && $livePriority <= $ceiling) {
            return null;
        }

        $priority = $this->priority();

        if ($apply) {
            Aws::elasticLoadBalancingV2()->setRulePriorities([
                'RulePriorities' => [['RuleArn' => $rule['RuleArn'], 'Priority' => $priority]],
            ]);

            $this->cachedRule = null;
        }

        return Change::make('priority', $livePriority, $priority);
    }

    protected function hostCondition(): array
    {
        return [
            'Field' => 'host-header',
            'HostHeaderConfig' => ['Values' => $this->hosts()],
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<int, string>
     */
    protected function liveHosts(array $rule): array
    {
        foreach ($rule['Conditions'] ?? [] as $condition) {
            if (($condition['Field'] ?? null) === 'host-header') {
                return $condition['HostHeaderConfig']['Values'] ?? $condition['Values'] ?? [];
            }
        }

        return [];
    }

    protected function sameHosts(array $live, array $desired): bool
    {
        return ! array_diff($live, $desired) && ! array_diff($desired, $live);
    }

    protected function find(): ?array
    {
        if ($this->cachedRule !== null) {
            return $this->cachedRule;
        }

        return $this->cachedRule = ElbV2::ruleByName($this->httpsListenerArn, $this->name());
    }

    /**
     * Which {@see PRIORITY_BANDS} entry this rule takes, derived from the
     * host-set: a rule carrying a wildcard host takes the wildcard band so every
     * exact-host rule on the shared listener outranks it. Derived rather than
     * declared per class because the same rule moves bands when the manifest
     * flips `wildcard-subdomains` — which {@see synchroniseConfiguration}
     * reconciles. The redirect rule overrides this.
     */
    protected function band(): string
    {
        foreach ($this->hosts() as $host) {
            if (str_starts_with($host, '*.')) {
                return 'wildcard';
            }
        }

        return 'forward';
    }

    protected function priority(): int
    {
        $usedPriorities = collect(ElbV2::rules($this->httpsListenerArn))
            ->filter(fn (array $rule): bool => $rule['Priority'] !== 'default')
            ->map(fn (array $rule): int => (int) $rule['Priority'])
            ->all();

        [$floor, $ceiling] = static::PRIORITY_BANDS[$this->band()];

        return static::nextAvailablePriority($this->name(), $usedPriorities, $floor, $ceiling);
    }

    /**
     * A stable per-name priority inside a band, skipping any already taken. The
     * band is explicit rather than defaulted: a caller that fell back to the full
     * 1000-49999 range could land a forward rule on top of a redirect and undo
     * the ordering {@see PRIORITY_BANDS} exists to guarantee.
     *
     * @param  array<int, int>  $usedPriorities
     */
    public static function nextAvailablePriority(string $name, array $usedPriorities, int $floor, int $ceiling): int
    {
        $range = $ceiling - $floor + 1;

        $base = (abs(crc32($name)) % $range) + $floor;

        for ($attempts = 0; in_array($base, $usedPriorities, true); $attempts++) {
            if ($attempts >= $range) {
                throw new IntegrityCheckException(sprintf('ALB listener rule priority space (%d-%d) exhausted', $floor, $ceiling));
            }

            $base = $base >= $ceiling ? $floor : $base + 1;
        }

        return $base;
    }
}
