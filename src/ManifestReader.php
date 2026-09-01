<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Enums\Service;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * The read-only manifest core shared by the CLI's static {@see Manifest} and
 * the runtime's `Yolo::manifest()`. Keys are dot-paths within the instance's
 * environment block.
 */
class ManifestReader
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(protected array $manifest, protected ?string $environment) {}

    /**
     * Missing or malformed loads as empty: a guest of the consuming app must
     * never break artisan — the CLI parses the same file loudly.
     */
    public static function load(string $path, ?string $environment): self
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
        return $this->serviceList($this->get('services', []));
    }

    /**
     * A claim in any environment counts, regardless of the instance's own —
     * the runtime's environment (e.g. `local`) may not exist in the manifest.
     */
    public function hasService(Service $service): bool
    {
        foreach ((array) ($this->manifest['environments'] ?? []) as $environment) {
            $services = $this->serviceList(is_array($environment) ? $environment['services'] ?? [] : []);

            if (in_array($service->value, $services, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function serviceList(mixed $services): array
    {
        return is_array($services) && array_is_list($services) ? $services : [];
    }

    protected function environmentKey(string $key): string
    {
        return sprintf('environments.%s.%s', $this->environment, $key);
    }
}
