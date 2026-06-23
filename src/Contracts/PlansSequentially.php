<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * Marker for a command whose plan pass must run **in-process**, never fanned out
 * across forked worker processes.
 *
 * The parallel plan (the forked fan-out in RunsSteppedCommands::executePlan) is a
 * read-only speed-up that matters for the constantly-run sync/deploy gate.
 * Teardown is the opposite case: it runs rarely, interactively, and its steps
 * make fork-unsafe AWS calls (ServiceDiscovery / Cloud Map in particular) that
 * can deadlock a forked plan worker — turning a teardown plan into a silent hang.
 * The apply pass is sequential regardless, so the fan-out buys teardown nothing,
 * and opting out of it sidesteps the fork hazard entirely.
 */
interface PlansSequentially {}
