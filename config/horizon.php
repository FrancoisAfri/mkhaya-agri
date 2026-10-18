<?php

/*
|--------------------------------------------------------------------------
| Laravel Horizon Configuration
|--------------------------------------------------------------------------
| Install: composer require laravel/horizon && php artisan horizon:install
| Publish assets: php artisan vendor:publish --provider="Laravel\Horizon\HorizonServiceProvider"
| Switch queue driver: QUEUE_CONNECTION=redis in .env
|
| Queue hierarchy (timeout ascending, workers descending):
|   notifications (30s)  → fastest, most workers
|   ai-fast      (60s)   → summary + interview scoring
|   documents    (90s)   → PDF generation
|   ai-heavy     (180s)  → CV parsing + fit scoring
|   default      (30s)   → everything else
*/

use Illuminate\Support\Str;

return [

    'domain' => null,
    'path'   => 'horizon',
    'driver' => 'redis',

    // Horizon dashboard protected by auth middleware
    'middleware' => ['web', 'auth'],

    // Alert thresholds — wait time in minutes before Horizon alerts
    'waits' => [
        'redis:ai-heavy'     => 5,
        'redis:ai-fast'      => 3,
        'redis:documents'    => 3,
        'redis:notifications'=> 1,
        'redis:default'      => 2,
    ],

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080, // 7 days
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    'memory_limit' => 128,

    'defaults' => [
        'supervisor-ai-heavy' => [
            'connection'           => 'redis',
            'queue'                => ['ai-heavy'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'size',
            'minProcesses'         => 1,
            'maxProcesses'         => 4,
            'balanceMaxShift'      => 1,
            'balanceCooldown'      => 3,
            'timeout'              => 180,
            'sleep'                => 3,
            'tries'                => 3,
            'nice'                 => 0,
        ],

        'supervisor-ai-fast' => [
            'connection'           => 'redis',
            'queue'                => ['ai-fast'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'size',
            'minProcesses'         => 2,
            'maxProcesses'         => 6,
            'balanceMaxShift'      => 2,
            'balanceCooldown'      => 3,
            'timeout'              => 60,
            'sleep'                => 3,
            'tries'                => 3,
            'nice'                 => 0,
        ],

        'supervisor-documents' => [
            'connection'           => 'redis',
            'queue'                => ['documents'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'size',
            'minProcesses'         => 1,
            'maxProcesses'         => 4,
            'balanceMaxShift'      => 1,
            'balanceCooldown'      => 3,
            'timeout'              => 90,
            'sleep'                => 3,
            'tries'                => 3,
            'nice'                 => 0,
        ],

        'supervisor-notifications' => [
            'connection'           => 'redis',
            'queue'                => ['notifications'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'size',
            'minProcesses'         => 2,
            'maxProcesses'         => 8,
            'balanceMaxShift'      => 2,
            'balanceCooldown'      => 3,
            'timeout'              => 30,
            'sleep'                => 3,
            'tries'                => 3,
            'nice'                 => 0,
        ],

        'supervisor-default' => [
            'connection'           => 'redis',
            'queue'                => ['default'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'size',
            'minProcesses'         => 2,
            'maxProcesses'         => 6,
            'balanceMaxShift'      => 1,
            'balanceCooldown'      => 3,
            'timeout'              => 30,
            'sleep'                => 3,
            'tries'                => 3,
            'nice'                 => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-ai-heavy' => [
                'connection'  => 'redis',
                'queue'       => ['ai-heavy'],
                'balance'     => 'auto',
                'minProcesses'=> 2,
                'maxProcesses'=> 4,
                'timeout'     => 180,
                'tries'       => 3,
            ],
            'supervisor-ai-fast' => [
                'connection'  => 'redis',
                'queue'       => ['ai-fast'],
                'balance'     => 'auto',
                'minProcesses'=> 3,
                'maxProcesses'=> 8,
                'timeout'     => 60,
                'tries'       => 3,
            ],
            'supervisor-documents' => [
                'connection'  => 'redis',
                'queue'       => ['documents'],
                'balance'     => 'auto',
                'minProcesses'=> 2,
                'maxProcesses'=> 5,
                'timeout'     => 90,
                'tries'       => 3,
            ],
            'supervisor-notifications' => [
                'connection'  => 'redis',
                'queue'       => ['notifications'],
                'balance'     => 'auto',
                'minProcesses'=> 3,
                'maxProcesses'=> 10,
                'timeout'     => 30,
                'tries'       => 3,
            ],
            'supervisor-default' => [
                'connection'  => 'redis',
                'queue'       => ['default'],
                'balance'     => 'auto',
                'minProcesses'=> 2,
                'maxProcesses'=> 6,
                'timeout'     => 30,
                'tries'       => 3,
            ],
        ],

        'local' => [
            'supervisor-local' => [
                'connection'  => 'redis',
                // Process all queues on a single local supervisor
                'queue'       => ['ai-heavy', 'ai-fast', 'documents', 'notifications', 'default'],
                'balance'     => 'simple',
                'minProcesses'=> 1,
                'maxProcesses'=> 3,
                'timeout'     => 180,
                'tries'       => 1,
            ],
        ],
    ],

];
