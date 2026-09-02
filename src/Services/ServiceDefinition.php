<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * Every surface that varies by service — offer schema, task-role grants, sync
 * steps per tier, build-time env injection, dashboard panels — hangs off one
 * definition, so adding a service is one class plus its steps/resources.
 * Definitions compose steps and resources; they never talk to AWS themselves.
 * An empty array is a valid decision.
 */
abstract class ServiceDefinition
{
    abstract public function service(): Service;

    /** Shown in the services table. */
    abstract public function description(): string;

    /**
     * Pre-filled when an operator first configures the service. A required
     * decision with no safe default is simply absent.
     *
     * @return array<string, int|string>
     */
    public function offerDefaults(): array
    {
        return [];
    }

    /**
     * Offer keys chosen from a fixed list, most-preferred first (the configurator
     * defaults to the first). Keys absent here get a free-text prompt.
     *
     * @return array<string, array<int, string>>
     */
    public function offerOptions(): array
    {
        return [];
    }

    /** Cost, blast radius, provisioning time — shown before the operator commits. */
    public function implications(): string
    {
        return '';
    }

    /**
     * Whether the service has env-shared infrastructure. App-side-only services
     * never appear in the env manifest's allowed keys and never enter the
     * lifecycle gate.
     */
    abstract public function envBacked(): bool;

    /**
     * Added to the app's ECS task role policy when it consumes the service.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function taskRoleStatements(): array;

    /**
     * Allowed keys beneath `services.{name}`; anything else hard-fails validation.
     *
     * @return array<int, string>
     */
    public function offerKeys(): array
    {
        return [];
    }

    /**
     * An offer is a map or nothing — a scalar or list would pass the allow-list
     * and then provision a misconfigured service. Overrides enforce required keys.
     */
    public function validateOffer(mixed $offer, string $filename): void
    {
        if ($offer === null || $offer === []) {
            return;
        }

        if (! is_array($offer) || array_is_list($offer)) {
            throw new IntegrityCheckException(sprintf(
                'services.%s in %s must be a map of offer config (services: { %s: {} }).',
                $this->service()->value,
                $filename,
                $this->service()->value,
            ));
        }
    }

    /**
     * Ordered sync:environment steps. Each gates itself on the lifecycle state,
     * so the list stays declared in every plan — skipping or tearing down when
     * the gate is off.
     *
     * @return array<int, class-string>
     */
    public function environmentSteps(): array
    {
        return [];
    }

    /**
     * Ordered sync:app steps, always composed into the plan; each self-gates on
     * the app's claim so an unclaimed service's resources melt away on the next sync.
     *
     * @return array<int, class-string>
     */
    public function appSteps(): array
    {
        return [];
    }

    /**
     * Ordered destroy:app steps, the mirror of {@see appSteps()}. A service whose
     * app side is task-role grants only (swept with the task role) needs none.
     *
     * @return array<int, class-string>
     */
    public function teardownAppSteps(): array
    {
        return [];
    }

    /**
     * Ordered destroy:environment steps — the sync steps' Teardown branches, but
     * listed dependents-before-dependencies because create order doesn't invert
     * cleanly (a listener rule must go before its target group).
     *
     * @return array<int, class-string>
     */
    public function teardownEnvironmentSteps(): array
    {
        return [];
    }

    /**
     * Injected unconditionally at build when the app consumes the service — YOLO-owned keys, not defaults.
     *
     * @return array<string, string>
     */
    public function buildValues(): array
    {
        return [];
    }

    /**
     * Must always return its keys (null/false when unconsumed) so the body builder can rely on every key existing.
     *
     * @return array<string, mixed>
     */
    public function dashboardContext(): array
    {
        return [];
    }

    /**
     * Half-width (12-col) widgets for `# Services`, packed two per row. Return [] when unconsumed.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function servicesWidgets(array $context): array
    {
        return [];
    }

    /**
     * Logs Insights panels as title => log-group-name; null values are dropped.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string|null>
     */
    public function logPanels(array $context): array
    {
        return [];
    }

    /**
     * A service that adds its own WebACL rule charts its blocks under `# WAF`
     * so everything WAF lands in one group. Return [] when unconsumed or the
     * WebACL isn't resolved yet.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function wafPanels(array $context): array
    {
        return [];
    }
}
