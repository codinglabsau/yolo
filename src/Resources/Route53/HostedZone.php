<?php

namespace Codinglabs\Yolo\Resources\Route53;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Commands\SyncAppCommand;
use Codinglabs\Yolo\Concerns\SyncsRecordSets;
use Codinglabs\Yolo\Concerns\ResolvesCanonicalHost;
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
    use ResolvesCanonicalHost;
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
     * The environment named by the zone's `yolo:environment` tag, or null when
     * the zone carries no such tag. Unlike {@see ownerEnvironment()} (a fail-soft
     * signal for a sync plan warning that swallows read errors), this lets a read
     * error PROPAGATE — the teardown gate must distinguish "unowned" from "couldn't
     * read" and fail closed on the latter, never delete on an inconclusive read.
     */
    public function ownerTag(): ?string
    {
        return Aws::flattenTags($this->liveTags(Str::afterLast($this->arn(), '/')))['yolo:environment'] ?? null;
    }

    /**
     * Tear down the Deletable contract — delegates to {@see teardown()}. The
     * ownership gate (only a zone this env owns) lives in TeardownHostedZoneStep,
     * which fails closed on an inconclusive ownership read.
     */
    public function delete(): void
    {
        $this->teardown();
    }

    /**
     * Remove only the records YOLO manages for this app (the canonical host + its
     * apex/www sibling A records), preserving every operator-added record — MX,
     * SPF/DKIM/DMARC TXT, verification CNAMEs. The zone itself is deleted only
     * when nothing but the apex NS + SOA remain afterwards (it was a pure-YOLO
     * zone); a zone still holding other records is left standing, because a real
     * domain outlives any single app and deleting it would take that DNS with it.
     *
     * @return bool whether the zone itself was deleted (false ⇒ kept, other records remain)
     */
    public function teardown(): bool
    {
        try {
            $id = Str::afterLast($this->arn(), '/');
        } catch (ResourceDoesNotExistException) {
            return true;
        }

        $this->deleteManagedRecords($id);

        if (! $this->onlyDefaultRecordsRemain($id)) {
            return false;
        }

        Aws::route53()->deleteHostedZone(['Id' => $id]);

        return true;
    }

    /**
     * The hostnames YOLO writes A-alias records for — the canonical host and,
     * when it's one half of the apex/www pair, its sibling. Mirrors
     * {@see SyncsRecordSets::generateChanges()} so
     * teardown removes exactly what sync created and nothing else.
     *
     * @return array<int, string>
     */
    protected function managedHosts(): array
    {
        $domain = Manifest::get('domain', $this->apex);

        return $this->hasWwwSibling($this->apex, $domain)
            ? [$domain, $this->wwwSibling($this->apex, $domain)]
            : [$domain];
    }

    /**
     * Delete only this app's A/AAAA alias records at the managed hosts. Anything
     * else in the zone (email/verification DNS, other apps' records) is left
     * untouched.
     */
    protected function deleteManagedRecords(string $id): void
    {
        $managed = collect($this->managedHosts())
            ->map(fn (string $host): string => rtrim($host, '.') . '.')
            ->all();

        $changes = collect(Aws::route53()->listResourceRecordSets(['HostedZoneId' => $id])['ResourceRecordSets'] ?? [])
            ->filter(fn (array $record): bool => in_array($record['Type'], ['A', 'AAAA'], true)
                && in_array(rtrim((string) $record['Name'], '.') . '.', $managed, true))
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

    /**
     * Whether the only records left in the zone are the apex NS + SOA that
     * Route 53 auto-manages (and removes with the zone) — i.e. it was a pure-YOLO
     * zone and is now safe to delete outright.
     */
    protected function onlyDefaultRecordsRemain(string $id): bool
    {
        $apex = rtrim($this->apex, '.') . '.';

        return collect(Aws::route53()->listResourceRecordSets(['HostedZoneId' => $id])['ResourceRecordSets'] ?? [])
            ->every(fn (array $record): bool => in_array($record['Type'], ['NS', 'SOA'], true) && rtrim((string) $record['Name'], '.') . '.' === $apex);
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
