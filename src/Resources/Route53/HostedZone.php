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
 * Route 53 hosted zone for a domain (the solo app's apex, or a tenant's), addressed
 * by domain so the solo and multitenancy steps share one resource.
 *
 * Unlike every other App resource a zone can't be env-prefixed: a real domain has one
 * zone shared by every environment serving this app on it. Record writes stay isolated
 * (each env UPSERTs only its own `domain`), but the `yolo:environment` tag would
 * collide, so it is first-writer-wins — {@see synchroniseTags()} never overwrites a
 * sibling env's value (that would flap every sync and read as drift, deadlocking both
 * envs' deploy gate). The shared ownership surfaces as a plan warning instead
 * ({@see SyncAppCommand}).
 */
class HostedZone implements Adoptable, Resource, Undeletable
{
    use ResolvesCanonicalHost;
    use ResolvesTags;

    /**
     * Identity is the apex alone. The record-management methods also need whose
     * records they manage — null means the app's own ({@see managedHosts()});
     * {@see forTenant()} names a tenant's.
     */
    public function __construct(
        protected string $apex,
        protected ?string $domain = null,
        protected ?string $wildcardHost = null,
    ) {}

    /**
     * Keyed to the tenant's own hosts so a withdrawal takes exactly what
     * {@see SyncMultitenancyRecordSetStep} wrote — never the app's or a sibling's.
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
     * Remove only the A/AAAA alias records YOLO inserted ({@see SyncsRecordSets});
     * email, verification and sibling-environment records are untouched. The zone
     * itself is never deleted ({@see Undeletable}): the registrar's delegation points
     * at it and the domain's whole DNS lives in it, so create ≠ own-to-destroy — like
     * the BYO data bucket.
     *
     * @return int record sets removed
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
     * The exact set {@see removeAppRecords()} would delete, so a teardown plan can
     * name each record.
     *
     * @return array<int, array{Type: string, Name: string}>
     */
    public function appRecords(): array
    {
        return collect(Aws::route53()->listResourceRecordSets(['HostedZoneId' => $this->zoneId()])['ResourceRecordSets'] ?? [])
            ->filter($this->isManagedRecord(...))
            ->map(fn (array $record): array => [
                'Type' => (string) $record['Type'],
                // `\052` decoded back to `*` so the plan names the wildcard as written.
                'Name' => rtrim(str_replace('\\052', '*', (string) $record['Name']), '.'),
            ])
            ->values()
            ->all();
    }

    public function appRecordsExist(): bool
    {
        return $this->appRecords() !== [];
    }

    protected function zoneId(): string
    {
        return Str::afterLast($this->arn(), '/');
    }

    /**
     * Shares {@see ResolvesCanonicalHost::aliasedHosts()} with
     * {@see SyncsRecordSets::generateChanges()} so teardown withdraws exactly what
     * sync created.
     *
     * @return array<int, string>
     */
    protected function managedHosts(): array
    {
        // Resolved here rather than defaulted inside aliasedHosts(), so a tenant's
        // zone can never inherit the app's wildcard.
        return $this->domain === null
            ? $this->aliasedHosts($this->apex, Manifest::domain() ?? $this->apex, Manifest::wildcardHost())
            : $this->aliasedHosts($this->apex, $this->domain, $this->wildcardHost);
    }

    /**
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
     * Route 53 stores a wildcard label as `\052` and returns it that way, so a
     * `*.example.com` record compared raw matches nothing and teardown would leave
     * it behind.
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

        // First-writer-wins: pin the expected value to the incumbent so a sibling
        // env's ownership is neither re-stamped nor reported as drift. The live
        // read is fed back as $read to keep it to one round-trip.
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
     * The *sibling* environment whose tag owns this zone, or null (absent, unowned,
     * ours, or the read failed — a soft signal for a plan warning, never worth
     * failing a sync).
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
