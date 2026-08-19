<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Facades\Yolo;
use Codinglabs\Yolo\Enums\Service;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * The app's yolo.yml as the runtime sees it — an instance read of the whole
 * file, container-bound and reachable through the {@see Yolo}
 * facade. The CLI's static {@see \Codinglabs\Yolo\Manifest} doesn't exist
 * here: it resolves the file through BASE_PATH and reads one selected
 * environment, neither of which the deployed app has. This side loads from
 * an explicit path and answers app-level questions across every
 * environment.
 */
class Manifest
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(protected array $manifest) {}

    /**
     * Load the manifest file at $path. A missing or malformed file loads as
     * empty rather than throwing: the CLI parses the same file loudly on
     * every build/sync, so this guest-of-the-app read must never be the
     * thing that breaks artisan.
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
     * Whether any environment claims the service. The runtime's form of the
     * CLI's {@see \Codinglabs\Yolo\Manifest::usesService()}: no environment
     * is selected at runtime, and an app either is or isn't a {service} app
     * — so a claim in any environment counts.
     */
    public function hasService(Service $service): bool
    {
        foreach ((array) ($this->manifest['environments'] ?? []) as $environment) {
            $services = is_array($environment) ? $environment['services'] ?? [] : [];

            if (is_array($services) && in_array($service->value, $services, true)) {
                return true;
            }
        }

        return false;
    }
}
