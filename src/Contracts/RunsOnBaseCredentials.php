<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * Marks a teardown step that runs on the operator's base identity, outside the
 * tier cap (apply pass only). destroy:environment assumes the env's admin role
 * but also deletes it — detaching AdminPolicy from the role the run is using
 * would strip the permissions the rest of the teardown needs, so the runner drops
 * back to the base profile before the first such step.
 */
interface RunsOnBaseCredentials {}
