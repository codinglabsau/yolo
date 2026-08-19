<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Enums\Service;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * The read-only core of the manifest, shared by both surfaces: the CLI's
 * static {@see Manifest} delegates its reads here with the selected
 * environment, and the runtime binds an instance from the app's base path
 * with `app()->environment()`, reachable as `Yolo::manifest()`. Keys are
 * dot-paths within the instance's environment block.
 */
class ManifestReader
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(protected array $manifest, protected ?string $environment = null) {}

    /**
     * A missing or malformed file loads as empty rather than throwing: the
     * runtime read is a guest of the consuming app and must never break
     * artisan — the CLI parses the same file loudly on every build/sync.
     */
    public static function load(string $path, ?string $environment = null): self
    {
        if (! is_file($path)) {
            return new self([], $environment);
        }

        try {
            $manifest = Yaml::parse((string) file_get_contents($path));
        } catch (ParseException) {
            return new self([], $environment);
        }

        return new self(is_array($manifest) ? $manifest : [], $environment);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->manifest, $this->environmentKey($key));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? Arr::get($this->manifest, $this->environmentKey($key)) : $default;
    }

    /**
     * @return array<int, string>
     */
    public function services(): array
    {
        $services = $this->get('services', []);

        return is_array($services) && array_is_list($services) ? $services : [];
    }

    /**
     * A claim in any environment counts, regardless of the instance's own —
     * an app either is or isn't a {service} app, and the runtime's
     * environment (e.g. `local`) may not exist in the manifest at all.
     */
    public function hasService(Service $service): bool
    {
        foreach (array_keys((array) ($this->manifest['environments'] ?? [])) as $environment) {
            $services = Arr::get($this->manifest, sprintf('environments.%s.services', $environment), []);

            if (is_array($services) && in_array($service->value, $services, true)) {
                return true;
            }
        }

        return false;
    }

    protected function environmentKey(string $key): string
    {
        return sprintf('environments.%s.%s', $this->environment, $key);
    }
}
