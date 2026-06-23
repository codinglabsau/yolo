<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * Marker for a teardown step that must run on the operator's **base identity**,
 * outside the YOLO tier cap — applied only during the apply pass.
 *
 * destroy:environment authenticates by assuming the env's admin role (that's
 * where the MFA gate comes from), but it also has to delete that role and its
 * AdminPolicy. Detaching AdminPolicy from the role the run is using strips the
 * very permissions the rest of the teardown needs, so the IAM-tier teardown
 * can't run under the assumed credentials it's deleting. These steps run last
 * (after the buckets and the network), and before the first of them executes the
 * runner drops the assumed credentials back to the base profile
 * (see RunsSteppedCommands::invokeStep → Command::ensureBaseCredentials), which
 * still holds the permissions to finish the job. The plan pass is unaffected —
 * it reads fine under the cap's observer policy.
 */
interface RunsOnBaseCredentials {}
