<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Iam;

/**
 * {@see ObserverPolicy} minus the `db:tunnel` port-forward: an agent inspects the
 * environment, it never opens a path to a database. Data reads are absent too —
 * they live on the data documents, which {@see AgentRole} never attaches.
 */
class AgentPolicy extends ObserverPolicy
{
    #[\Override]
    public function name(): string
    {
        return $this->keyedName(Iam::AGENT_POLICY);
    }

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    #[\Override]
    public function description(): string
    {
        return 'YOLO managed read-only inspection for coding agents - the observer surface without data reads or database tunnels';
    }

    #[\Override]
    public function sessionStatements(): array
    {
        return [];
    }
}
