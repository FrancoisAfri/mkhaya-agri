<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VAPID keys for Web Push (FR-5.1)
    |--------------------------------------------------------------------------
    | Generate with: php artisan fleet:generate-vapid
    | Or manually via: npx web-push generate-vapid-keys
    */
    'vapid_public_key'  => env('VAPID_PUBLIC_KEY', ''),
    'vapid_private_key' => env('VAPID_PRIVATE_KEY', ''),
    'vapid_subject'     => env('VAPID_SUBJECT', 'mailto:admin@mkhaya.co.za'),

    /*
    |--------------------------------------------------------------------------
    | AI provider keys — overrides config/ai.php for the Fleet module
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'claude'   => env('ANTHROPIC_API_KEY'),
        'openai'   => env('OPENAI_API_KEY'),
        'gemini'   => env('GEMINI_API_KEY'),
        'deepseek' => env('DEEPSEEK_API_KEY'),
    ],
];
