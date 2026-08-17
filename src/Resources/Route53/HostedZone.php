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
use Codinglabs\Yolo\Steps\Deploy\SyncMultitenancyRecordSetStep;

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

    /**
     * A zone is identified by its apex alone — every caller that only needs its
     * identity (existence, ARN, tags) passes that and nothing else.
     *
     * The record-management methods additionally need to know *whose* records
     * they manage, since a tenant zone holds that tenant's hosts and wildcard,
     * not the app's. Left null they default to the app's own hosts
     * ({@see managedHosts()}); {@see forTenant()} names a tenant's instead.
     */
    public function __construct(
        protected string $apex,
        protected ?string $domain = null,
        protected ?string $wildcardHost = null,
    ) {}

    /**
     * The zone holding one tenant's records, keyed to that tenant's own hosts so a
     * withdrawal takes exactly what {@see SyncMultitenancyRecordSetStep}
     * wrote — never the app's, and never a sibling tenant's.
     *
     * @param  array<string, mixed>  $config  a {@see Manifest::tenants()} entry
     */
    public static function forTenant(array $config): self
    {
        return new self((string) $config['apex'], (string) $config['domain'], $config['wildcard-host'] ?? null);
    }

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
                // `\052` decoded back to `*` so the teardown plan names the wildcard
                // record the way the operator wrote it.
                'Name' => rtrim(str_replace('\\052', '*', (string) $record['Name']), '.'),
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
     * The hostnames YOLO writes A-alias records for — the canonical host, its
     * apex/www sibling when it has one, and the wildcard when the app serves its
     * own subdomains — plus, for the app's own zone, any additional landlord host
     * ({@see Manifest::additionalDomains()}) that resolves to THIS apex (a
     * landlord may declare hosts across several zones; each zone only manages the
     * ones that are actually its own). Shares
     * {@see ResolvesCanonicalHost::aliasedHosts()} with
     * {@see SyncsRecordSets::generateChanges()} so teardown withdraws exactly what
     * sync created and nothing else.
     *
     * @return array<int, string>
     */
    protected function managedHosts(): array
    {
        // A zone constructed without a domain manages the app's own records; one
        // built by forTenant() manages that tenant's. Resolved here rather than
        // defaulted inside aliasedHosts(), so a tenant's zone can never inherit the
        // app's wildcard (which would write `*.{app domain}` into the tenant's zone).
        if ($this->domain !== null) {
            return $this->aliasedHosts($this->apex, $this->domain, $this->wildcardHost);
        }

        // The primary domain's own apex/www/wildcard treatment belongs ONLY to the
        // zone that IS its apex — a landlord spanning several zones must never
        // attribute the primary host to a foreign zone just because this instance
        // has no explicit $domain.
        $primary = Manifest::hasDomain() && Manifest::apex() === $this->apex
            ? $this->aliasedHosts($this->apex, (string) Manifest::domain(), Manifest::wildcardHost())
            : [];

        $additional = collect(Manifest::additionalDomains())
            ->filter(fn (string $host): bool => Manifest::deriveApex($host) === $this->apex)
            ->all();

        return array_values(array_unique([...$primary, ...$additional]));
    }

    /**
     * An A/AAAA record at one of this app's managed hosts — i.e. one YOLO inserted.
     *
     * @param  array<string, mixed>  $record
     */
    protected function isManagedRecord(array $record): bool
    {
        $managed = collect($this->managedHosts())
            ->map($this->normaliseRecordName(...))
            ->all();

        return in_array($record['Type'], ['A', 'AAAA'], true)
            && in_array($this->normaliseRecordName((string) $record['Name']), $managed, true);
    }

    /**
     * A record name in one comparable form: fully qualified, lower-cased, and with
     * Route 53's octal escaping decoded. Route 53 stores a wildcard label as
     * `\052` and returns it that way on read, so a `*.example.com` record YOLO
     * wrote comes back as `\052.example.com.` — compared raw it matches nothing
     * and teardown would leave the wildcard record behind.
     */
    protected function normaliseRecordName(string $name): string
    {
        return strtolower(rtrim(str_replace('\\052', '*', $name), '.')) . '.';
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
