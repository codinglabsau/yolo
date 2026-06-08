<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
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
abstract class ListenerRule implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;

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
     * Reconcile the live rule's host conditions and action onto the desired ones,
     * in place — so changing `domain` (apex↔www, or to a different host) rewrites
     * this app's existing rule rather than orphaning it and creating a new one.
     * Only this rule (found by Name) is ever modified.
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

        return $changes;
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

    protected function priority(): int
    {
        $usedPriorities = collect(ElbV2::rules($this->httpsListenerArn))
            ->filter(fn (array $rule): bool => $rule['Priority'] !== 'default')
            ->map(fn (array $rule): int => (int) $rule['Priority'])
            ->all();

        return static::nextAvailablePriority($this->name(), $usedPriorities);
    }

    public static function nextAvailablePriority(string $name, array $usedPriorities): int
    {
        $floor = 1000;
        $ceiling = 49999;
        $range = $ceiling - $floor + 1;

        $base = (abs(crc32($name)) % $range) + $floor;

        for ($attempts = 0; in_array($base, $usedPriorities, true); $attempts++) {
            if ($attempts >= $range) {
                throw new IntegrityCheckException('ALB listener rule priority space (1000-49999) exhausted');
            }

            $base = $base >= $ceiling ? $floor : $base + 1;
        }

        return $base;
    }
}
