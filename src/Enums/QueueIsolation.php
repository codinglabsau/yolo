<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * How a multi-tenant app's tenants map onto SQS queues and worker programs.
 *
 * - Dedicated (default): each tenant gets its own queue(s) — `…-{tenant}[-tier]` —
 *   and supervisord runs one queue:work program per tenant (plus landlord), so a
 *   whale tenant's backlog can't starve the others. Fair, but N tenants means N
 *   worker programs and N queues per tier — it scales to dozens, not hundreds.
 *
 * - Shared: every tenant shares one queue set at the app's own name (`…[-tier]`,
 *   the same shape a solo app has), with the tenant carried in the job payload. One
 *   worker program per tier drains all tenants, so it scales to any tenant count —
 *   at the cost of cross-tenant fairness (a whale floods the shared queue). The
 *   right default for a high-tenant-count app.
 *
 * Orthogonal to LPX-587's per-tenant `dedicated: true`, which is a third axis — one
 * tenant getting its own independently-scaled ECS service.
 */
enum QueueIsolation: string
{
    case Dedicated = 'dedicated';
    case Shared = 'shared';
}
