<?php

arch()->preset()->php();

// The runtime surfaces (the service provider + Runtime classes) boot inside the
// deployed app, where config:cache resolves configuration — so they read it through
// config(), never env(). (env() stays legitimate in YOLO's CLI/console code, which
// has no config layer; this rule deliberately does not cover it.)
arch('runtime-in-app code reads config(), not env()')
    ->expect('env')
    ->not->toBeUsedIn([
        'Codinglabs\Yolo\YoloServiceProvider',
        'Codinglabs\Yolo\Runtime',
    ]);
