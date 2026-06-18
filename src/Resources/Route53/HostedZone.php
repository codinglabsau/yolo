<?php

namespace Codinglabs\Yolo\Resources\Route53;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Commands\SyncAppCommand;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Route 53 hosted zone for a domain (the solo app's apex, or a tenant's apex).
 * Addressed by domain so the solo and multitenancy steps share one resource.
 *
 * Unlike every other App resource, a hosted zone can't be env-prefixed — a real
 * domain has one zone, shared by every environment that serves this app on it (a
 * trial env on `staging.example.com` alongside prod on `example.com`). Record
 * writes stay isolated regardless: each env UPSERTs only its own `domain`, and a
 * bare subdomain has no apex/www sibling, so a trial never touches prod's records.
 * The one thing that would collide is the `yolo:environment` ownership tag, so it
 * is first-writer-wins: {@see synchroniseTags()} never overwrites a sibling env's
 * value (that would flap every sync and read as drift — which would deadlock both
 * environments' deploy in-sync gate). The shared ownership surfaces as a sync
 * plan warning instead ({@see SyncAppCommand}).
 */
class HostedZone implements Deletable, Resource
{
    use ResolvesTags;

    public function __construct(protected string $apex) {}

    public function name(): string
    {
        return $this->apex;
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            Route53::hostedZone($this->apex);

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Route53::hostedZone($this->apex)['Id'];
    }

    public function create(): void
    {
        Aws::route53()->createHostedZone([
            'CallerReference' => Str::uuid(),
            'Name' => $this->apex,
        ]);

        $this->synchroniseTags(apply: true);
    }

    /**
     * Tear the zone down — but NEVER one a sibling environment owns: a shared
     * domain (a trial env alongside prod) keeps its zone for as long as any env
     * still serves it, so ownership by another env is a hard skip. When this env
     * does own it (or it's unowned), every record set is removed — except the
     * apex NS + SOA that Route 53 manages and deletes with the zone — and then
     * the zone itself is deleted. A zone that's already gone is the goal state.
     */
    public function delete(): void
    {
        if ($this->ownerEnvironment() !== null) {
            return;
        }

        try {
            $id = Str::afterLast($this->arn(), '/');
        } catch (ResourceDoesNotExistException) {
            return;
        }

        $this->deleteRecordSets($id);

        Aws::route53()->deleteHostedZone(['Id' => $id]);
    }

    /**
     * Empty the zone of every deletable record — a zone with any record set
     * other than the apex NS + SOA can't be deleted. Those two are auto-managed
     * by Route 53 and removed with the zone, so they're left in place.
     */
    protected function deleteRecordSets(string $id): void
    {
        $apex = rtrim($this->apex, '.') . '.';

        $changes = collect(Aws::route53()->listResourceRecordSets(['HostedZoneId' => $id])['ResourceRecordSets'] ?? [])
            ->reject(fn (array $record): bool => in_array($record['Type'], ['NS', 'SOA'], true) && rtrim((string) $record['Name'], '.') . '.' === $apex)
            ->map(fn (array $record): array => ['Action' => 'DELETE', 'ResourceRecordSet' => $record])
            ->values()
            ->all();

        if ($changes === []) {
            return;
        }

        Aws::route53()->changeResourceRecordSets([
            'HostedZoneId' => $id,
            'ChangeBatch' => ['Changes' => $changes],
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        $id = Str::afterLast($this->arn(), '/');
        $current = Aws::flattenTags($this->liveTags($id));

        $tags = $this->tags();
        $owner = $current['yolo:environment'] ?? null;

        // First-writer-wins on the environment tag: when a sibling environment
        // already owns this shared zone, pin the expected value to the incumbent
        // so it's neither re-stamped (endless flapping) nor reported as drift
        // (which would refuse both envs' deploys via the in-sync gate). Reading
        // the live tags once and feeding them back as the reconcile's $read keeps
        // this to a single AWS round-trip.
        if ($owner !== null && $owner !== Helpers::app('environment')) {
            $tags['yolo:environment'] = $owner;
        }

        return Aws::reconcileTags(
            $tags,
            fn (): array => $current,
            fn (array $missing) => Aws::route53()->changeTagsForResource([
                'ResourceType' => 'hostedzone',
                'ResourceId' => $id,
                'AddTags' => Aws::keyValueTags($missing),
            ]),
            $apply,
        );
    }

    /**
     * The environment whose `yolo:environment` tag currently owns this zone, or
     * null when the zone is absent or unowned (or the read fails — a soft signal
     * for a plan warning, never worth failing a sync over). Returns null when the
     * current environment is the owner: "owner" means a *sibling* env holds it.
     */
    public function ownerEnvironment(): ?string
    {
        try {
            if (! $this->exists()) {
                return null;
            }

            $owner = Aws::flattenTags($this->liveTags(Str::afterLast($this->arn(), '/')))['yolo:environment'] ?? null;

            return $owner !== null && $owner !== Helpers::app('environment') ? $owner : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function liveTags(string $id): array
    {
        return Aws::route53()->listTagsForResource([
            'ResourceType' => 'hostedzone',
            'ResourceId' => $id,
        ])['ResourceTagSet']['Tags'] ?? [];
    }
}
