<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Licensing master switch
    |--------------------------------------------------------------------------
    | Licensing is DORMANT until a public key is configured below — so the app
    | behaves exactly as before until you deliberately turn it on by issuing a
    | keypair. With a public key present and `enforce` true, unlicensed modules
    | are hard-blocked (the chosen fail mode).
    */
    'enforce' => env('LICENSE_ENFORCE', true),

    /*
    | Base64 Ed25519 PUBLIC key, embedded in the app to verify license
    | signatures. Generate with `php artisan license:keygen`. The matching
    | PRIVATE key never leaves your control and is used only to issue licenses.
    | Empty = licensing disabled (everything allowed).
    */
    'public_key' => env('LICENSE_PUBLIC_KEY', ''),

    /*
    | Where the signed license file lives on each install.
    */
    'path' => env('LICENSE_PATH', storage_path('app/license.json')),

    /*
    | Optional explicit server fingerprint source. If unset, the fingerprint is
    | derived from /etc/machine-id (Linux) or the hostname. Set this when a host
    | lacks a stable machine-id (e.g. some containers) to pin the binding.
    */
    'server_id' => env('LICENSE_SERVER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Remote validation / kill-switch (client side)
    |--------------------------------------------------------------------------
    | When enabled, each install periodically heartbeats the authority so you can
    | REVOKE it remotely (non-payment / abuse / theft). An explicit revoke is an
    | immediate hard-stop; if the authority is simply unreachable, the install
    | keeps working for `grace_hours` (so your outage doesn't take clients down)
    | and only then blocks.
    */
    'remote'        => env('LICENSE_REMOTE', false),
    'server_url'    => env('LICENSE_SERVER_URL'),
    'remote_secret' => env('LICENSE_REMOTE_SECRET'),
    'grace_hours'   => env('LICENSE_GRACE_HOURS', 72),

    /*
    | Set true on the ONE instance you host as the licensing authority (it then
    | answers /license/validate and exposes the registry). Leave false on client
    | installs.
    */
    'authority' => env('LICENSE_AUTHORITY', false),

];
