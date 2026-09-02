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
 * Identity is the rule's stable `Name` tag, never the hosts it routes: the
 * host-set is configuration a domain change rewrites in place. Keying on hosts
 * would make a domain change look like a new rule (orphaning the old one) and an
 * apex↔www swap match the *other* rule; Name identity also means sync never
 * touches a sibling host's rule (another app, or a hand-rolled one).
 */
abstract class ListenerRule implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    /**
     * ALB evaluates rules lowest-number first, and a wildcard forward rule
     * (`*.{apex}`) also matches `www.{apex}` and `search.{apex}` — without bands,
     * whichever rule hashed lower would win. Redirect outranks every forward
     * rule and exact-host forward outranks wildcard; within a band the number is
     * a stable hash of the rule name so it survives syncs.
     */
    protected const PRIORITY_BANDS = [
        'redirect' => [1000, 9999],
        'forward' => [10000, 29999],
        'wildcard' => [30000, 49999],
    ];

    protected ?array $cachedRule = null;

    public function __construct(protected string $httpsListenerArn) {}

    /**
     * @return array<int, string>
     */
    abstract public function hosts(): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function action(): array;

    /**
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
     * Removes only this app's rule — never the shared `:443` listener. Runs ahead
     * of the target group's delete so the forward action's reference is gone first.
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
     * Rewrites this rule in place so a `domain` change never orphans it, and a
     * `wildcard-subdomains` flip moves it into the band its new host-set demands.
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
     * The band is derived from the DESIRED host-set so a rule whose hosts changed
     * band is moved — without this, banding would only govern freshly-created rules.
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
     * Derived from the host-set rather than declared per class: the same rule
     * moves bands when the manifest flips `wildcard-subdomains`.
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
     * The band is explicit, never defaulted: falling back to the full 1000-49999
     * range could land a forward rule above a redirect.
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
