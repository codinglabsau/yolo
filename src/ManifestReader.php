<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Facades\Yolo;
use Codinglabs\Yolo\Enums\Service;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * The read-only core of the manifest — a parsed yolo.yml with no context
 * assumptions, so both surfaces drive the same logic: the CLI's static
 * {@see Manifest} delegates its reads here (supplying BASE_PATH resolution
 * and the selected environment), and the runtime binds an instance loaded
 * from the app's base path, reachable as `Yolo::manifest()` through the
 * {@see Yolo} facade. Environment-scoped reads take
 * the environment as an argument — selecting one is the caller's context,
 * never this class's.
 */
class ManifestReader
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(protected array $manifest) {}

    /**
     * Load the manifest file at $path. A missing or malformed file loads as
     * empty rather than throwing: the runtime read is a guest of the
     * consuming app and must never be the thing that breaks artisan — the
     * CLI parses the same file loudly on every build/sync.
     */
    public static function load(string $path): self
    {
        if (! is_file($path)) {
            return new self([]);
        }

        try {
            $manifest = Yaml::parse((string) file_get_contents($path));
        } catch (ParseException) {
            return new self([]);
        }

        return new self(is_array($manifest) ? $manifest : []);
    }

    /**
     * The services the given environment claims — bare capability names
     * (`services: [ivs]`).
     *
     * @return array<int, string>
     */
    public function services(string $environment): array
    {
        $services = $this->manifest['environments'][$environment]['services'] ?? [];

        return is_array($services) && array_is_list($services) ? $services : [];
    }

    /**
     * Whether the manifest claims the service — in the given environment, or
     * in any environment when none is given. The no-environment form is the
     * runtime's question: nothing selects an environment inside the deployed
     * app, and an app either is or isn't a {service} app.
     */
    public function hasService(Service $service, ?string $environment = null): bool
    {
        $environments = $environment === null
            ? array_keys((array) ($this->manifest['environments'] ?? []))
            : [$environment];

        foreach ($environments as $candidate) {
            if (in_array($service->value, $this->services((string) $candidate), true)) {
                return true;
            }
        }

        return false;
    }
}
