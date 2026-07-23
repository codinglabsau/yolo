<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * How a multi-tenant app's tenants map onto SQS queues and worker programs.
 *
 * - Shared (default): every tenant shares one queue set at the app's own name
 *   (`…[-tier]`, the same shape a solo app has), with the tenant carried in the job
 *   payload. One worker per tier drains all tenants, so it scales to any tenant count.
 *   Cross-tenant fairness is the trade — a whale floods the shared queue — but that's
 *   the right default; per-tenant isolation is the exception you opt into.
 *
 * - Dedicated: each tenant gets its own queue(s) — `…-{tenant}[-tier]` — and
 *   supervisord runs one queue:work program per tenant (plus landlord), so a whale
 *   tenant's backlog can't starve the others. Fair, but N tenants means N worker
 *   programs and N queues per tier — it scales to dozens, not hundreds.
 *
 * Orthogonal to LPX-587's per-tenant `dedicated: true`, which is a third axis — one
 * tenant getting its own independently-scaled ECS service.
 */
enum QueueIsolation: string
{
    case Shared = 'shared';
    case Dedicated = 'dedicated';
}
