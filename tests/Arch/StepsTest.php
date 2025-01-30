<?php

arch()
    ->expect('Codinglabs\Yolo\Steps')
    ->toBeInvokable()
    ->toHaveSuffix('Step');

arch()
    ->expect('Codinglabs\Yolo\Steps\Start\All')
    ->toImplement('Codinglabs\Yolo\Contracts\RunsOnAws');

arch()
    ->expect('Codinglabs\Yolo\Steps\Start\Queue')
    ->toImplement('Codinglabs\Yolo\Contracts\RunsOnAwsQueue');

arch()
    ->expect('Codinglabs\Yolo\Steps\Start\RunsOnAwsScheduler')
    ->toImplement('Codinglabs\Yolo\Contracts\RunsOnAws');

arch()
    ->expect('Codinglabs\Yolo\Steps\Start\Web')
    ->toImplement('Codinglabs\Yolo\Contracts\RunsOnAwsWeb');
