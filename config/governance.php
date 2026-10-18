<?php

return [

    'session' => [
        'timeout_minutes' => (int) env('GOVERNANCE_SESSION_TIMEOUT', 10),
    ],

    'password' => [
        'max_days' => (int) env('GOVERNANCE_PASSWORD_MAX_DAYS', 60),
    ],

    'azure' => [
        'auto_provision' => (bool) env('AZURE_AUTO_PROVISION', false),
    ],

];
