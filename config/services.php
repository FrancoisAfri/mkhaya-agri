<?php

return [

    // Tender Pursuit auto-discovery feed (OCDS releases endpoint, e.g. SA eTenders).
    'etenders' => [
        'url'     => env('TENDER_DISCOVERY_URL'),
        'timeout' => (int) env('TENDER_DISCOVERY_TIMEOUT', 60), // the OCDS API is slow; 25s often isn't enough
        'retries' => (int) env('TENDER_DISCOVERY_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Shared secret for the CRM email deliverability webhook (bounce/spam/blocked).
    'crm_email_webhook' => [
        'secret' => env('CRM_EMAIL_WEBHOOK_SECRET'),
    ],
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'openai' => [
    'key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-pro'),
    ],

    'deepseek' => [
        'key' => env('DEEPSEEK_API_KEY'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
    ],

    'whatsapp' => [
        'access_token'    => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    ],

    'webpush' => [
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'azure' => [
        'client_id'     => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'redirect'      => env('AZURE_REDIRECT_URI', '/auth/azure/callback'),
        'tenant'        => env('AZURE_TENANT_ID', 'common'),
        'proxy'         => env('PROXY'),
    ],

    // Mkhaya Move (Fleet) shuttle API — server side. This is the credential
    // Mkhaya Move issues to a connecting client; the client stores it (encrypted)
    // in their shuttle integration and presents it as a bearer token. The
    // endpoint validates incoming calls against it. Client-side connection
    // details live in conference_integrations (provider "mkhaya_move"), not here.
    'payroll_payouts' => [
        'webhook_secret' => env('PAYROLL_PAYOUT_WEBHOOK_SECRET'),
    ],

    'mkhaya_move' => [
        'token' => env('MKHAYA_MOVE_TOKEN'),
    ],

    // Google OAuth for Search Console + Analytics (Content Marketing analytics).
    // Set up an OAuth client in Google Cloud with scopes webmasters.readonly +
    // analytics.readonly and add the redirect URI: <APP_URL>/content-marketing/analytics/google/callback
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    // Social OAuth for one-click "Connect" of publishing channels (Content Marketing #22).
    // Each is an app you register with the platform; redirect URI is
    // <APP_URL>/content-marketing/social/oauth/<platform>/callback
    'twitter' => [
        'client_id'     => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
    ],
    'facebook' => [
        'client_id'     => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
    ],
    'linkedin' => [
        'client_id'     => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
    ],

    // Google PageSpeed Insights — optional. When set, Website Audit upgrades the
    // performance/accessibility/mobile scores to real Lighthouse results. Free key
    // from https://developers.google.com/speed/docs/insights/v5/get-started. Without
    // it the audit falls back to on-page heuristics (keyless PSI is quota-blocked).
    // Shared secret in the bounce/complaint webhook URL. Without it the endpoint refuses
    // everything, which is the right default for a public route that suppresses addresses.
    'mail_events' => [
        'token' => env('MAIL_EVENTS_TOKEN'),
    ],

    'pagespeed' => [
        'key'     => env('PAGESPEED_API_KEY'),
        'enabled' => env('PAGESPEED_ENABLED', true),
    ],

    // AI Campaign Manager — ad-network APIs. Optional; when set, live publishing/metrics for that
    // platform become available. Without them the connectors report that setup is required.
    'google_ads' => [
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'client_id'       => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret'   => env('GOOGLE_ADS_CLIENT_SECRET'),
    ],
    'meta_ads' => [
        'app_id'     => env('META_ADS_APP_ID'),
        'app_secret' => env('META_ADS_APP_SECRET'),
    ],

];
