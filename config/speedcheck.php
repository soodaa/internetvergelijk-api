<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Parallelisatie instellingen
    |--------------------------------------------------------------------------
    */
    'concurrency' => (int) env('SPEEDCHECK_CONCURRENCY', 4),
    'timeout' => (float) env('SPEEDCHECK_TIMEOUT', 10.0),

    /*
    |--------------------------------------------------------------------------
    | Retry instellingen
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'attempts' => (int) env('SPEEDCHECK_RETRY_ATTEMPTS', 1),
        'timeout' => (float) env('SPEEDCHECK_RETRY_TIMEOUT', 4.0),
        'drivers' => [
            // Optionele per-driver overrides
            'kpn' => (float) env('SPEEDCHECK_RETRY_TIMEOUT_KPN', 3.0),
            'ziggo' => (float) env('SPEEDCHECK_RETRY_TIMEOUT_ZIGGO', 3.0),
            'odido' => (float) env('SPEEDCHECK_RETRY_TIMEOUT_ODIDO', 4.0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker instellingen
    |--------------------------------------------------------------------------
    */
    'circuit_breaker' => [
        'failure_threshold' => (int) env('SPEEDCHECK_CB_FAILURE_THRESHOLD', 3),
        'window_seconds' => (int) env('SPEEDCHECK_CB_WINDOW_SECONDS', 300),
        'open_seconds' => (int) env('SPEEDCHECK_CB_OPEN_SECONDS', 60),
    ],
];

