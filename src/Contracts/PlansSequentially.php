<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * Marks a command whose plan pass runs in-process, never forked: teardown steps
 * make fork-unsafe AWS calls (ServiceDiscovery / Cloud Map in particular) that
 * can deadlock a forked plan worker into a silent hang.
 */
interface PlansSequentially {}
