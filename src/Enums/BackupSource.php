<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * Where a backup dumps from on an Aurora cluster. Reader (default) keeps the dump
 * off the writer and refuses a cluster with no reader; Writer is the explicit
 * opt-in for a writer-only cluster.
 */
enum BackupSource: string
{
    case Reader = 'reader';
    case Writer = 'writer';
}
