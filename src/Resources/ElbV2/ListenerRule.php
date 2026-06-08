<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * Identity is the host-set this rule routes (not a stable AWS name). exists() is
 * a search across the HTTPS listener's rules; create() allocates a deterministic
 * priority via a CRC32 hash of the rule's name so the same app lands on the same
 * priority across re-creates.
 */
class ListenerRule implements Resource
{
    use ResolvesTags;

    protected ?array $cachedRule = null;

    public function __construct(protected string $httpsListenerArn) {}

    public function name(): string
    {
        return $this->keyedName();
    }

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
            'Conditions' => [
                [
                    'Field' => 'host-header',
                    'HostHeaderConfig' => ['Values' => static::routedHosts()],
                ],
            ],
            'Actions' => [
                [
                    'Type' => 'forward',
                    'TargetGroupArn' => (new TargetGroup())->arn(),
                ],
            ],
            ...Aws::tags($this->tags()),
        ]);

        $this->cachedRule = null;
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElbV2Tags($this->arn(), $this->tags(), $apply);
    }

    protected function find(): ?array
    {
        if ($this->cachedRule !== null) {
            return $this->cachedRule;
        }

        $hosts = static::routedHosts();

        foreach (ElbV2::rules($this->httpsListenerArn) as $rule) {
            foreach ($rule['Conditions'] ?? [] as $condition) {
                if ($condition['Field'] !== 'host-header') {
                    continue;
                }

                $values = $condition['HostHeaderConfig']['Values'] ?? $condition['Values'] ?? [];

                if (! array_diff($hosts, $values) && ! array_diff($values, $hosts)) {
                    return $this->cachedRule = $rule;
                }
            }
        }

        return null;
    }

    protected function priority(): int
    {
        $usedPriorities = collect(ElbV2::rules($this->httpsListenerArn))
            ->filter(fn (array $rule): bool => $rule['Priority'] !== 'default')
            ->map(fn (array $rule): int => (int) $rule['Priority'])
            ->all();

        return static::nextAvailablePriority($this->name(), $usedPriorities);
    }

    public static function routedHosts(): array
    {
        $apex = Manifest::apex();
        $domain = Manifest::get('domain', $apex);

        return $domain === $apex
            ? [$apex, "www.$apex"]
            : [$domain];
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
