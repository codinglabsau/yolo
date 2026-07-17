<?php

namespace Codinglabs\Yolo\Resources\Route53;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Adoptable;
use Codinglabs\Yolo\Resources\Undeletable;
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
class HostedZone implements Adoptable, Resource, Undeletable
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
     * Remove only the records YOLO inserted for this app — the canonical host and,
     * when it's one half of the apex/www pair, its sibling (the A/AAAA alias records
     * {@see SyncsRecordSets} writes). Every other record is left untouched: email
     * (MX/SPF/DKIM), domain-verification, and any sibling environment's records.
     *
     * The hosted zone itself is NEVER deleted (the class is {@see Undeletable}). A
     * zone is domain-level infrastructure — the registrar's NS delegation points at
     * it and the domain's whole DNS lives in it — so it outlives any single app;
     * `destroy:app` only withdraws the records it added. (YOLO creates the zone on
     * first sync as a convenience, but create ≠ own-to-destroy — exactly like the
     * BYO data bucket.)
     *
     * @return int the number of record sets removed (0 ⇒ nothing of ours remained)
     */
    public function removeAppRecords(): int
    {
        $changes = collect(Aws::route53()->listResourceRecordSets(['HostedZoneId' => $this->zoneId()])['ResourceRecordSets'] ?? [])
            ->filter($this->isManagedRecord(...))
            ->map(fn (array $record): array => ['Action' => 'DELETE', 'ResourceRecordSet' => $record])
            ->values()
            ->all();

        if ($changes === []) {
            return 0;
        }

        Aws::route53()->changeResourceRecordSets([
            'HostedZoneId' => $this->zoneId(),
            'ChangeBatch' => ['Changes' => $changes],
        ]);

        return count($changes);
    }

    /**
     * The live records YOLO inserted for this app — the exact set
     * {@see removeAppRecords()} would delete, as {Type, Name} pairs (trailing dot
     * trimmed) so a teardown plan can name each record it withdraws rather than a
     * vague "the app's DNS records".
     *
     * @return array<int, array{Type: string, Name: string}>
     */
    public function appRecords(): array
    {
        return collect(Aws::route53()->listResourceRecordSets(['HostedZoneId' => $this->zoneId()])['ResourceRecordSets'] ?? [])
            ->filter($this->isManagedRecord(...))
            ->map(fn (array $record): array => [
                'Type' => (string) $record['Type'],
                'Name' => rtrim((string) $record['Name'], '.'),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether the zone still holds any of this app's managed records — the
     * plan-pass / re-run check, so teardown reports WOULD_DELETE vs SKIPPED without
     * writing.
     */
    public function appRecordsExist(): bool
    {
        return $this->appRecords() !== [];
    }

    protected function zoneId(): string
    {
        return Str::afterLast($this->arn(), '/');
    }

    /**
     * The hostnames YOLO writes A-alias records for — the canonical host and,
     * when it's one half of the apex/www pair, its sibling. Mirrors
     * {@see SyncsRecordSets::generateChanges()} so teardown withdraws exactly what
     * sync created and nothing else.
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
     * An A/AAAA record at one of this app's managed hosts — i.e. one YOLO inserted.
     *
     * @param  array<string, mixed>  $record
     */
    protected function isManagedRecord(array $record): bool
    {
        $managed = collect($this->managedHosts())
            ->map(fn (string $host): string => rtrim($host, '.') . '.')
            ->all();

        return in_array($record['Type'], ['A', 'AAAA'], true)
            && in_array(rtrim((string) $record['Name'], '.') . '.', $managed, true);
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
