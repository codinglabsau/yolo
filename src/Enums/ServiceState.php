<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * Decided solely by whether the environment manifest declares the service, never
 * by app consumption — so a consumer being down at sync time can't tear the
 * service out from under it. An unused declared service is a plan warning.
 */
enum ServiceState
{
    case Provision;

    case Teardown;
}
