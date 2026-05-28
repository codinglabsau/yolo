<?php

namespace Codinglabs\Yolo\Resources\Route53;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Route 53 hosted zone for a domain (the solo app's apex, or a tenant's apex).
 * Addressed by domain so the solo and multitenancy steps share one resource.
 */
class HostedZone implements Resource
{
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

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseRoute53Tags($this->arn(), $this->tags(), $apply);
    }
}
