<?php

// BASE_PATH is define()d at runtime by the `yolo` entrypoint (and by tests),
// so static analysis never sees it. Define it here so PHPStan can resolve the
// references in src/Paths.php without flagging an undefined constant.
if (! defined('BASE_PATH')) {
    define('BASE_PATH', getcwd() ?: __DIR__);
}
