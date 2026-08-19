<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Burst autoscaling
    |--------------------------------------------------------------------------
    |
    | Runtime signals for the web tier's burst step-scaling, both injected on the
    | web task definition by YOLO's sync (SyncTaskDefinitionStep) and read here so
    | `config:cache` bakes them. The deploy-all entrypoint hooks (e.g. `php artisan
    | optimize`) run at container start with the ECS environment present, so the
    | cached values are correct.
    |
    | - service: the ECS service name the metric is dimensioned by; its presence is
    |   the gate that registers the saturation reporter (no separate enabled flag).
    | - cpu: the task's vCPU allocation — the denominator the CPU fallback divides
    |   usage by. It's injected rather than read locally because the Fargate microVM
    |   exposes more vCPUs than a fractional task is throttled to.
    |
    */

    'burst' => [
        'service' => env('YOLO_BURST_SERVICE'),
        'cpu' => env('YOLO_BURST_CPU'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search self-healing
    |--------------------------------------------------------------------------
    |
    | When the app is wired for the Typesense Scout driver, YOLO's provider
    | schedules `scout:heal` itself (every five minutes) — the search index is
    | a rebuildable projection, and a wiped collection should rebuild without
    | anyone remembering a kernel line. This flag is the opt-out.
    |
    */

    'search' => [
        'heal' => env('YOLO_SEARCH_HEAL', true),
    ],

];
