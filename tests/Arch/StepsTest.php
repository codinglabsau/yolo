<?php

arch()
    ->expect('Codinglabs\Yolo\Steps')
    ->toBeInvokable()
    ->toHaveSuffix('Step');
