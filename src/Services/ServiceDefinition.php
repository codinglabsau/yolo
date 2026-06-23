<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * The composition root for one YOLO-provisioned service. Every surface that
 * varies by service — the env-manifest offer schema, the app task-role grants,
 * the sync steps each tier runs, build-time env injection and dashboard
 * panels — hangs off the service's single definition, so adding a service
 * means writing one class (plus its steps/resources) rather than editing six
 * scattered surfaces. The `Service` enum stays the name registry and resolves
 * to a definition via `Service::definition()`.
 *
 * Definitions compose existing steps and resources; they never talk to AWS
 * themselves. The abstract methods are the decisions every service must make —
 * an empty array is a valid decision (a service whose app side is env
 * injection only needs no runtime IAM).
 */
abstract class ServiceDefinition
{
    /** The enum case this definition belongs to — the service's registered name. */
    abstract public function service(): Service;

    /** A one-line, human description of the capability — shown in the services table. */
    abstract public function description(): string;

    /**
     * Sensible defaults for the env-manifest offer keys, pre-filled when an
     * operator first configures the service (e.g. Typesense's 256 vCPU / 1024 MB
     * per node, 3 nodes). A required decision with no safe default (the Typesense
     * version) is simply absent.
     *
     * @return array<string, int|string>
     */
    public function offerDefaults(): array
    {
        return [];
    }

    /**
     * Offer keys whose value is chosen from a fixed list rather than typed free,
     * keyed by offer key — options ordered most-preferred first (the configurator
     * presents a select and defaults to the first, or to the current value when
     * re-editing). Keys absent here fall back to a free-text prompt.
     *
     * @return array<string, array<int, string>>
     */
    public function offerOptions(): array
    {
        return [];
    }

    /**
     * A short warning of the immediate, real-world implications of turning the
     * service on — cost, blast radius, provisioning time — shown before the
     * operator commits. Empty when there's nothing material to flag.
     */
    public function implications(): string
    {
        return '';
    }

    /**
     * Whether this service has an environment-manifest half — env-shared
     * infrastructure that sync:environment provisions when the environment
     * offers `services.{name}` and a live app claims it. App-side-only
     * services have nothing to declare env-side, so they never appear in the
     * env manifest's allowed keys and never enter the lifecycle gate.
     */
    abstract public function envBacked(): bool;

    /**
     * The IAM statements consuming this service adds to the app's ECS task
     * role policy — the app-side half of the service contract.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function taskRoleStatements(): array;

    /**
     * The keys this service's env-manifest offer block may declare beneath
     * `services.{name}` (e.g. version/cpu/memory). Anything else hard-fails
     * the manifest's allow-list validation.
     *
     * @return array<int, string>
     */
    public function offerKeys(): array
    {
        return [];
    }

    /**
     * Validate a declared offer block's shape. The base rule: an offer is a
     * map (or nothing) — `services: { ivs: {} }` — never a scalar or a list,
     * which would validate against the allow-list and then provision a
     * misconfigured service. Services with required offer keys override this
     * and enforce them.
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
     * The ordered sync:environment steps that provision (and tear down) this
     * service's env-shared resources. Each step gates itself on the lifecycle
     * state, so the step list itself stays declared — present in every plan,
     * skipping or tearing down when the two-key gate is off.
     *
     * @return array<int, class-string>
     */
    public function environmentSteps(): array
    {
        return [];
    }

    /**
     * The ordered sync:app steps for this service's per-app resources. Always
     * composed into the plan; each step self-gates on the app's own claim so
     * an unclaimed service's per-app resources melt away on the next sync.
     *
     * @return array<int, class-string>
     */
    public function appSteps(): array
    {
        return [];
    }

    /**
     * The ordered destroy:app steps that tear this service's per-app resources
     * down — the mirror of {@see appSteps()}, composed into destroy:app the same
     * way appSteps composes into sync:app. Each step self-gates, so it skips for
     * an app that never consumed the service. A service whose app side is task-role
     * grants only (swept with the task role) needs none.
     *
     * @return array<int, class-string>
     */
    public function teardownAppSteps(): array
    {
        return [];
    }

    /**
     * The ordered destroy:environment steps that tear this service's env-shared
     * resources down. Reuses the sync steps' Teardown branches (destroy:environment
     * runs them with the lifecycle forced to Teardown), but listed in teardown
     * order — dependents before dependencies — because create order doesn't invert
     * cleanly (e.g. a search listener rule must go before its target group). Empty
     * for a service with no env-shared half.
     *
     * @return array<int, class-string>
     */
    public function teardownEnvironmentSteps(): array
    {
        return [];
    }

    /**
     * Build-time env values injected (unconditionally — these are YOLO-owned
     * keys, not defaults) when the app consumes this service.
     *
     * @return array<string, string>
     */
    public function buildValues(): array
    {
        return [];
    }

    /**
     * This service's entries in the dashboard's resolved context — always
     * returning its keys (null/false when the app doesn't consume the service)
     * so the body builder can rely on every key existing.
     *
     * @return array<string, mixed>
     */
    public function dashboardContext(): array
    {
        return [];
    }

    /**
     * Widget property maps for the dashboard's `# Services` section, built
     * from the resolved context. Each entry renders as a half-width (12-col)
     * metric panel; the dashboard packs them two per row. Return [] when the
     * context says the app doesn't consume the service.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function servicesWidgets(array $context): array
    {
        return [];
    }

    /**
     * Logs Insights panels this service contributes to the dashboard's
     * `# Logs` section, as title => log-group-name (null values are dropped).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string|null>
     */
    public function logPanels(array $context): array
    {
        return [];
    }

    /**
     * WAF metric panels this service contributes to the dashboard's `# WAF`
     * section — a service that adds its own WebACL rule (e.g. a per-host rate
     * limit) charts its blocks here, so everything WAF lands in one group
     * rather than scattered through `# Services`. Same property-map shape as
     * servicesWidgets; return [] when the app doesn't consume the service or
     * the WebACL isn't resolved yet.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function wafPanels(array $context): array
    {
        return [];
    }
}
