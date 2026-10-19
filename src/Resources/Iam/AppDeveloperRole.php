<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Deletable;

/**
 * Per-app {@see DeveloperRole}, so a developer grant can name a single app.
 * Carries the per-app observer, data-read and dumps-read documents; never OIDC-trusted.
 */
class AppDeveloperRole extends DeveloperRole implements Deletable
{
    #[\Override]
    public function scope(): Scope
    {
        return Scope::App;
    }

    #[\Override]
    public function description(): string
    {
        return 'YOLO managed developer role for this app: read-only inspection plus its database dump reads';
    }
}
