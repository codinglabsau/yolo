<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * What sync should do about an env-backed service, decided by one fact: whether
 * the environment manifest declares it (`services.{name}`). Declaration is the
 * operator's deliberate, billed decision to run the service — it stands up
 * independent of whether any app currently consumes it (so a consumer being
 * down at sync time can never tear the service out from under it). An unused
 * declared service is surfaced as a plan warning, not torn down.
 */
enum ServiceState
{
    /** Declared by the environment manifest — sync it toward the manifest. */
    case Provision;

    /** Not declared — tear it down. */
    case Teardown;
}
